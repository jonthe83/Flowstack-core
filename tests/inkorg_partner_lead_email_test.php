<?php

declare(strict_types=1);

/** Partnerns From blir aldrig kundens e-post. Adressen tas ur mejltexten. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-partner-lead-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ple(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function ple_body(string $name, string $email, string $portal): string
{
    return "Hej,\n\nNy lead från {$portal}.\n\nNamn: {$name}\nE-post: {$email}\nTelefon: 070-111 22 33\nAdress: Exempelvägen 4, 177 00 Järfälla\n\nMeddelande:\nVi vill ha batteri till villan.\n";
}

function ple_ingest(string $id, string $from, string $fromName, string $body, string $source = 'graph-jarfalla'): array
{
    return jeab_inkorg_ingest([
        'message_id' => $id . '@partner.test',
        'from' => $from,
        'from_name' => $fromName,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Ny lead',
        'body_text' => $body,
        'source' => $source,
        'thread_key' => 'ple-' . $id,
        'test' => true,
    ]);
}

function ple_thread(string $tid): array
{
    foreach (jeab_inkorg_threads() as $t) {
        if ((string) ($t['id'] ?? '') === $tid) {
            return $t;
        }
    }
    return [];
}

function ple_contact_emails(): array
{
    $out = [];
    foreach (jeab_inkorg_contacts(true) as $c) {
        if (!is_array($c)) {
            continue;
        }
        foreach ((array) ($c['emails'] ?? []) as $email) {
            $out[] = (string) $email;
        }
    }
    return $out;
}

$cases = [
    'nordsolen' => ['hej@nordsolen.se', 'Nordsolen', 'Kim Exempel', 'kim.exempel@gmail.com'],
    'hitta' => ['leads@hitta-solceller.se', 'Hitta-Solceller', 'Vera Exempel', 'vera.exempel@gmail.com'],
    'solceller' => ['lead@solceller.nu', 'Solceller.nu', 'Maja Exempel', 'maja.exempel@gmail.com'],
    'hemsol' => ['hej@hemsol.se', 'HemSol', 'Olle Exempel', 'olle.exempel@gmail.com'],
    'noreply' => ['noreply@okand-portal.example', 'Okand Portal', 'Noa Exempel', 'noa.exempel@gmail.com'],
    'notify' => ['notify@okand-portal.example', 'Okand Portal', 'Nils Exempel', 'nils.exempel@gmail.com'],
];
foreach ($cases as $id => [$from, $portal, $name, $email]) {
    $res = ple_ingest($id, $from, $portal, ple_body($name, $email, $portal));
    ple(!empty($res['ok']), $id . ' intagen');
    $thread = ple_thread((string) ($res['thread_id'] ?? ''));
    $party = jeab_inkorg_party($thread);
    ple($party['email'] === $email && $party['source'] === 'body', $id . ' kunden är ' . $email . ', fick ' . $party['email'] . '/' . $party['source']);
    ple($party['name'] === $name, $id . ' namn ' . $name . ', fick ' . $party['name']);
    if (jeab_lead_notify_local($from) && !jeab_lead_partner_address($from)) {
        ple(!jeab_inkorg_address_blocked($email), $id . ' kunden är inte blockerad');
    } else {
        ple(jeab_inkorg_address_blocked($from) && !jeab_inkorg_address_blocked($email), $id . ' partnern är blockerad, kunden är det inte');
    }
    $meta = jeab_inkorg_thread_meta((string) $thread['id']);
    ple((string) ($meta['customer_email'] ?? '') === $email, $id . ' tråden sparar kundens adress');
}
$stored = ple_contact_emails();
ple(in_array('kim.exempel@gmail.com', $stored, true), 'kontakten får kundens adress');
foreach (['hej@nordsolen.se', 'leads@hitta-solceller.se', 'lead@solceller.nu', 'hej@hemsol.se', 'noreply@okand-portal.example', 'notify@okand-portal.example'] as $partner) {
    ple(!in_array($partner, $stored, true), $partner . ' sparas inte som kontakt');
}

$plain = ple_ingest('vanlig', 'maja.kund@gmail.com', 'Maja Kund', "Hej!\n\nE-post: annan.person@gmail.com\nJag vill ha offert på solceller.\n");
$plainParty = jeab_inkorg_party(ple_thread((string) ($plain['thread_id'] ?? '')));
ple($plainParty['email'] === 'maja.kund@gmail.com' && $plainParty['source'] === 'from', 'vanligt mejl behåller avsändaren, fick ' . $plainParty['email']);

file_put_contents(jeab_inkorg_dir() . '/settings.json', json_encode([
    'lead_sellers' => [['domain' => 'exempelportal.se', 'added' => '']],
], JSON_UNESCAPED_UNICODE));
ple(jeab_lead_seller_match('desk@exempelportal.se') === 'exempelportal.se', 'sparad lista gäller');
ple(jeab_lead_partner_address('hej@nordsolen.se'), 'nordsolen är portal även när listan bytts ut');
$extra = ple_ingest('lista', 'desk@exempelportal.se', 'Exempelportalen', ple_body('Lina Exempel', 'lina.exempel@gmail.com', 'Exempelportalen'));
$extraParty = jeab_inkorg_party(ple_thread((string) ($extra['thread_id'] ?? '')));
ple($extraParty['email'] === 'lina.exempel@gmail.com' && $extraParty['source'] === 'body', 'konfigurerad portal tar kunden ur texten, fick ' . $extraParty['email']);
ple(!in_array('desk@exempelportal.se', ple_contact_emails(), true), 'konfigurerad portal sparas inte som kontakt');

$emptyBody = ple_ingest('tom', 'hej@nordsolen.se', 'Nordsolen', "Ny lead utan e-post i texten.\nNamn: Saknar Adress\n");
$emptyParty = jeab_inkorg_party(ple_thread((string) ($emptyBody['thread_id'] ?? '')));
ple($emptyParty['email'] === '', 'portal utan kundadress i texten får ingen partneradress, fick ' . $emptyParty['email']);
$supplier = ple_ingest('lev', 'notify@lightleap.example', 'Lightleap', "Order LL-1\nAll lines in stock.\n");
$supplierParty = jeab_inkorg_party(ple_thread((string) ($supplier['thread_id'] ?? '')));
ple($supplierParty['email'] === 'notify@lightleap.example' && $supplierParty['source'] === 'from', 'notify utan kundadress i texten förblir avsändaren');

$hs = ple_ingest('import', 'hej@nordsolen.se', 'Nordsolen', ple_body('Kim Exempel', 'kim.import@gmail.com', 'Nordsolen'), 'helpscout_import');
ple(!empty($hs['ok']), 'import intagen');
$hsThread = ple_thread((string) ($hs['thread_id'] ?? ''));
$hsParty = jeab_inkorg_party($hsThread);
ple($hsParty['email'] === 'kim.import@gmail.com', 'import/promote-tråd tar kunden ur texten, fick ' . $hsParty['email']);
ple((string) (jeab_inkorg_thread_meta((string) $hsThread['id'])['customer_email'] ?? '') === 'kim.import@gmail.com', 'import sparar kundens adress på tråden');
ple(!in_array('hej@nordsolen.se', ple_contact_emails(), true), 'import skapar ingen kontakt på partnern');

$hint = jeab_hs_import_prefer_body_customer([
    'name' => 'Nordsolen',
    'email' => 'hej@nordsolen.se',
    'phone' => '',
    'address' => '',
    'subject' => 'Ny lead',
    'body' => ple_body('Kim Exempel', 'kim.hint@gmail.com', 'Nordsolen'),
]);
ple((string) ($hint['email'] ?? '') === 'kim.hint@gmail.com' && (string) ($hint['name'] ?? '') === 'Kim Exempel', 'Help Scout-hint byter partnerns adress mot kunden');
$plan = jeab_hs_import_contact_plan($hint);
ple((string) ($plan['hint']['email'] ?? '') === 'kim.hint@gmail.com', 'kontaktplanen får kundens adress');
ple((string) ($plan['hint']['email'] ?? '') !== 'hej@nordsolen.se', 'kontaktplanen får inte partnerns adress');

$hemsolHint = jeab_hs_import_prefer_body_customer([
    'name' => 'HemSol',
    'email' => 'hej@hemsol.se',
    'body' => ple_body('Olle Exempel', 'olle.hint@gmail.com', 'HemSol'),
    'subject' => 'Ny lead',
]);
ple((string) ($hemsolHint['email'] ?? '') === 'olle.hint@gmail.com', 'HemSol-hint tar kunden ur texten');
$hittaHint = jeab_hs_import_prefer_body_customer([
    'name' => 'Hitta-Solceller',
    'email' => 'leads@hitta-solceller.se',
    'body' => ple_body('Vera Exempel', 'vera.hint@gmail.com', 'Hitta-Solceller'),
    'subject' => 'Ny lead',
]);
ple((string) ($hittaHint['email'] ?? '') === 'vera.hint@gmail.com', 'Hitta-Solceller-hint tar kunden ur texten');
$nuHint = jeab_hs_import_prefer_body_customer([
    'name' => 'Solceller.nu',
    'email' => 'lead@solceller.nu',
    'body' => ple_body('Maja Exempel', 'maja.hint@gmail.com', 'Solceller.nu'),
    'subject' => 'Ny lead',
]);
ple((string) ($nuHint['email'] ?? '') === 'maja.hint@gmail.com', 'Solceller.nu-hint tar kunden ur texten');

$kept = jeab_hs_import_prefer_body_customer([
    'name' => 'Maja Kund',
    'email' => 'maja.kund@gmail.com',
    'body' => "Hej!\nE-post: annan.person@gmail.com\n",
    'subject' => 'Fråga',
]);
ple((string) ($kept['email'] ?? '') === 'maja.kund@gmail.com', 'vanlig Help Scout-kund behåller sin adress');

$linkPartner = jeab_kontakt_source_from_inbox('c-partner', [
    'name' => 'Nordsolen',
    'emails' => ['hej@nordsolen.se', 'kim.link@gmail.com'],
]);
ple((string) ($linkPartner['email'] ?? '') === 'kim.link@gmail.com', 'kontakter.link hoppar över partneradressen');
$linkOnly = jeab_kontakt_source_from_inbox('c-only', [
    'name' => 'HemSol',
    'emails' => ['hej@hemsol.se'],
]);
ple((string) ($linkOnly['email'] ?? '') === '', 'kontakter.link utan kundadress lämnar e-post tom');

jeab_inkorg_thread_write('ple-nordsolen', static function (array $t): array {
    $t['customer_email'] = 'hej@nordsolen.se';
    return $t;
});
file_put_contents(jeab_inkorg_dir() . '/contacts.json', json_encode([
    'contacts' => [
        'c-bad' => ['name' => 'HemSol', 'emails' => ['hej@hemsol.se'], 'mobile' => '', 'address' => '', 'town' => ''],
    ],
], JSON_UNESCAPED_UNICODE));
$counts = jeab_lead_partner_customer_counts();
ple(($counts['threads'] ?? -1) === 1 && ($counts['inbox_contacts'] ?? -1) === 1 && ($counts['contacts'] ?? -1) === 0, 'räkningen ser partner som kund, trådar ' . (int) ($counts['threads'] ?? -1) . ' inkorg ' . (int) ($counts['inbox_contacts'] ?? -1));
$encoded = json_encode($counts);
ple(is_string($encoded) && !str_contains($encoded, '@'), 'räkningen innehåller bara antal');

echo "partner lead email ok\n";
