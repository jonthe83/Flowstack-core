<?php

declare(strict_types=1);

/**
 * Ledande «och», «all» och lösa komman ska inte bli gatans början.
 * Bara vid inläsning. Lagrad adress tolkas som den står.
 */

$tmp = sys_get_temp_dir() . '/jeab-addr-filler-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/address_lookup.php';
require_once dirname(__DIR__) . '/src/manuell_order.php';
require_once dirname(__DIR__) . '/src/inkorg_contacts.php';

function af(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$GLOBALS['_JEAB_KONTAKT_POSTORT_LOOKUP'] = static function (string $street, string $postal): array {
    $fold = function_exists('mb_strtolower') ? mb_strtolower($street, 'UTF-8') : strtolower($street);
    if (str_contains($fold, 'dimvägen 38') && str_contains($fold, 'järfälla')) {
        return ['postal_code' => '17770', 'city' => 'Järfälla'];
    }

    return [];
};

$kept = [
    'Dimvägen 38' => 'Dimvägen 38',
    'Allégatan 4' => 'Allégatan 4',
    'Allén 3, Stockholm' => 'Allén 3, Stockholm',
    'Sommarvägen 2' => 'Sommarvägen 2',
    'Allhelgonagatan 2' => 'Allhelgonagatan 2',
    'Attundavägen 5' => 'Attundavägen 5',
    'Medborgarplatsen 1' => 'Medborgarplatsen 1',
    'All 12' => 'All 12',
    'Plus 2' => 'Plus 2',
    'Via Appia 1' => 'Via Appia 1',
    'och, All 12' => 'All 12',
];
foreach ($kept as $in => $out) {
    af(jeab_address_strip_leading_filler($in) === $out, 'behåller «' . $out . '»');
}

$stripped = [
    'och, Dimvägen 38' => 'Dimvägen 38',
    'och Dimvägen 38' => 'Dimvägen 38',
    'Och, Dimvägen 38, 177 70 Järfälla' => 'Dimvägen 38, 177 70 Järfälla',
    'all, Storgatan 1' => 'Storgatan 1',
    ', Dimvägen 38' => 'Dimvägen 38',
    'och, all, Dimvägen 38' => 'Dimvägen 38',
    'och, , Dimvägen 38' => 'Dimvägen 38',
    'samt Storgatan 1' => 'Storgatan 1',
    'och' => '',
    'och, all' => '',
];
foreach ($stripped as $in => $out) {
    af(jeab_address_strip_leading_filler($in) === $out, 'rensar «' . $in . '»');
}

$stored = jeab_kontakt_parse_address('och, Dimvägen 38');
af(str_starts_with((string) ($stored['street'] ?? ''), 'och'), 'lagrad adress tolkas oförändrad');

$lead = static function (string $body): string {
    $cust = jeab_lead_customer([
        'from' => 'info@wix-forms.com',
        'from_name' => 'Formulär',
        'body' => $body,
    ]);

    return (string) ($cust['address'] ?? '');
};

af($lead("Namn:\nAda Test\n\nAdress och ort:\n\nDimvägen 38, 17770 Järfälla\n\nE-post:\nada.filler@gmail.com\n") === 'Dimvägen 38, 17770 Järfälla', 'Adress och ort behåller gatan');

$split = $lead("Namn:\nAda Test\n\nAdress\noch\nort\nDimvägen 38\n\nE-post:\nada.split@gmail.com\n");
af($split !== '' && !str_starts_with(mb_strtolower($split), 'och') && str_contains($split, 'Dimvägen 38'), 'delad etikett Adress / och / ort blir gatan, fick ' . $split);

$colon = $lead("Adress: och, Dimvägen 38\nPostnummer: 177 70\nOrt: Järfälla\nE-post: ada.colon@gmail.com\n");
af(str_starts_with($colon, 'Dimvägen 38') && !str_contains(mb_strtolower($colon), 'och,'), 'adressfält med och-komma, fick ' . $colon);

$plain = $lead("Adress: Dimvägen 38, 177 70 Järfälla\nE-post: ada.plain@gmail.com\n");
af(str_contains($plain, 'Dimvägen 38') && str_contains($plain, 'Järfälla'), 'vanlig adressrad står kvar');

$sig = jeab_inkorg_find_address("Mvh\nOch Dimvägen 38\n177 70 Järfälla\n");
af(($sig['address'] ?? '') === 'Dimvägen 38, 17770' && ($sig['town'] ?? '') === 'Järfälla', 'signaturens och-prefix tas bort');
$sigPlain = jeab_inkorg_find_address("Mvh\nDimvägen 38\n177 70 Järfälla\n");
af(($sigPlain['address'] ?? '') === 'Dimvägen 38, 17770', 'vanlig signatur står kvar');

$leadBase = [
    'lead_id' => 'omt-filler-1',
    'created_at' => '2026-10-20T10:00:00+02:00',
    'lead_type' => 'Batteri',
    'property_type' => 'Villa',
    'contact' => [
        'first_name' => 'Nora',
        'last_name' => 'Exempel',
        'email' => 'nora.filler@gmail.com',
        'phone' => '070-000 00 21',
    ],
    'address' => [
        'street' => 'och, Dimvägen 38',
        'city' => 'Järfälla',
        'postal_code' => '177 70',
    ],
    'summary' => 'Vill ha batteri.',
    'profile' => [['label' => 'Tak', 'value' => 'Tegel']],
    'test' => true,
];
$okLead = jeab_omtago_validate($leadBase);
af(($okLead['lead']['street'] ?? '') === 'Dimvägen 38', 'omtago rensar och-prefix');
$onlyOch = $leadBase;
$onlyOch['address']['street'] = 'och';
$bad = jeab_omtago_validate($onlyOch);
af(($bad['error'] ?? '') === 'address.street saknas.', 'omtago utan gata avvisas');
$alle = $leadBase;
$alle['address']['street'] = 'Allégatan 4';
$alleLead = jeab_omtago_validate($alle);
af(($alleLead['lead']['street'] ?? '') === 'Allégatan 4', 'omtago behåller Allégatan');

$owners = jeab_rot_owners_from_input([
    'owner_name' => ['Test Testsson'],
    'owner_address' => ['och, Dimvägen 38'],
    'owner_pnr' => [''],
]);
af(count($owners) === 1 && ($owners[0]['address'] ?? '') === 'Dimvägen 38', 'manuell ägaradress rensas');
af(jeab_manuell_order_clean_address('all, Storgatan 1') === 'Storgatan 1', 'manuell postadress rensas');
af(jeab_manuell_order_clean_address('Dimvägen 38') === 'Dimvägen 38', 'manuell vanlig gata står kvar');

$leadSrc = (string) file_get_contents(dirname(__DIR__) . '/src/lead_source.php');
af(str_contains($leadSrc, 'jeab_lead_customer'), 'lead_source läser adressen via samma lead-tolk');
