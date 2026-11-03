<?php

declare(strict_types=1);

/**
 * Intern avsändare och egen domän är aldrig kunden.
 * Listetikett och kundruta matchar kundens e-post eller telefon.
 * Saknas träff visas ingen kontakt. Påhittade rader. Inget personnummer.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-internal-sender-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function isc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
isc($store instanceof JeabPdoStore, 'sqlite-lagret finns');
jeab_ensure_contacts_tables($store->pdo(), 'sqlite');
isc(jeab_contacts_sole(), 'CONTACTS_SOLE är på');
$box = jeab_contact_store();
isc($box instanceof JeabPdoContactStore, 'kontaktlagret är SQL');

$save = static function (string $id, array $owner, array $extra = []) use ($box): void {
    $row = array_merge([
        'id' => $id,
        'email' => (string) ($owner['email'] ?? ''),
        'phone' => (string) ($owner['phone'] ?? ''),
        'street' => (string) ($extra['street'] ?? ''),
        'city' => (string) ($extra['city'] ?? ''),
        'postal_code' => (string) ($extra['postal_code'] ?? ''),
        'owners' => [array_merge(['contact' => true], $owner)],
    ], $extra);
    $box->upsert($id, $row);
    if (function_exists('jeab_contacts_sole_cache_forget')) {
        jeab_contacts_sole_cache_forget($id);
    }
};

$save('c-david', ['name' => 'David Kulneff', 'email' => '', 'phone' => '']);
$save('c-kund', [
    'name' => 'Kund Exempel',
    'email' => 'kund.exempel@example.test',
    'phone' => '0701234567',
], ['street' => 'Exempelgatan 4', 'city' => 'Teststad', 'postal_code' => '21122']);
$save('c-kim', [
    'name' => 'Kim Telefon',
    'email' => '',
    'phone' => '0707654321',
], ['street' => 'Telefonvagen 2', 'city' => 'Teststad', 'postal_code' => '21123']);
$save('c-sam', [
    'name' => 'Sam Wendel',
    'email' => 'saljare8@malmo.example.test',
    'phone' => '',
]);
$save('c-anna', [
    'name' => 'Anna Extern',
    'email' => 'anna.extern@example.test',
    'phone' => '0701112233',
], ['street' => 'Annagatan 1', 'city' => 'Teststad', 'postal_code' => '21124']);

$david = jeab_kontakt_get('c-david');
isc(is_array($david) && jeab_kontakt_row_email($david) === '' && jeab_kontakt_row_phone($david) === '', 'felkopplad kontakt saknar e-post och telefon');
isc(jeab_contacts_inbox_contact_is_internal(jeab_kontakt_get('c-sam') ?: []), 'personal på egen domän är intern');
isc(!jeab_contacts_inbox_email_is_customer('saljare8@malmo.example.test'), 'egen domän är inte en kundnyckel');
isc(jeab_contacts_inbox_email_is_customer('kund.exempel@example.test'), 'kundens e-post är en kundnyckel');

$staffFrom = [
    'from' => 'saljare8@malmo.example.test',
    'from_name' => 'Sam Wendel',
    'to' => 'kontakt@malmo.example.test',
    'source' => 'graph-malmo',
    'received_at' => '2026-10-20T10:00:00Z',
];

$withMail = [
    'id' => 'thr-wendel-mail',
    'contact_id' => 'c-david',
    'customer_name' => 'Sam Wendel',
    'customer_email' => 'saljare8@malmo.example.test',
    'company' => 'malmo',
    'messages' => [array_merge($staffFrom, [
        'body' => "Vidarebefordrat.\nE-post: kund.exempel@example.test\nTelefon: 070-123 45 67\nExempelgatan 4\n",
    ])],
    'last' => array_merge($staffFrom, [
        'body' => "Vidarebefordrat.\nE-post: kund.exempel@example.test\nTelefon: 070-123 45 67\n",
    ]),
    'message_count' => 6,
];
$panel = jeab_inkorg_panel_contact($withMail);
$boxFields = jeab_inkorg_contact_box($withMail, is_array($panel) ? $panel : null, ['name' => 'Sam Wendel', 'email' => 'saljare8@malmo.example.test', 'phone' => '']);
$place = jeab_inkorg_panel_place($withMail, is_array($panel) ? $panel : null);
isc(is_array($panel) && (string) ($panel['name'] ?? '') === 'Kund Exempel', 'kundrutan tar kunden i mejlet');
isc((string) ($panel['contact_id'] ?? '') === 'c-kund' && (string) ($panel['id'] ?? '') === 'c-kund', 'kundrutan pekar inte på den felkopplade kontakten');
isc(in_array('kund.exempel@example.test', (array) ($boxFields['emails'] ?? []), true), 'kundrutan visar kundens e-post');
isc((string) ($boxFields['phone'] ?? '') !== '' && str_contains((string) $place['address'], 'Exempelgatan 4'), 'kundrutan visar telefon och adress');
isc(!str_contains((string) ($panel['name'] ?? ''), 'David') && !str_contains((string) ($panel['name'] ?? ''), 'Sam'), 'varken fel kontakt eller personal blir kunden');
isc(jeab_inkorg_fast_who($withMail) === 'Sam Wendel', 'listans avsändarnamn är kvar');

$bare = [
    'id' => 'thr-wendel-bare',
    'contact_id' => 'c-david',
    'customer_name' => 'Sam Wendel',
    'customer_email' => 'saljare8@malmo.example.test',
    'company' => 'malmo',
    'messages' => [array_merge($staffFrom, ['body' => "Hej, jag tar mötet imorgon.\n"])],
    'last' => array_merge($staffFrom, ['from_name' => 'Sam Wendel']),
    'message_count' => 6,
];
$barePanel = jeab_inkorg_panel_contact($bare);
$bareBox = jeab_inkorg_contact_box($bare, is_array($barePanel) ? $barePanel : null, ['name' => 'Sam Wendel', 'email' => '', 'phone' => '']);
$barePlace = jeab_inkorg_panel_place($bare, is_array($barePanel) ? $barePanel : null);
isc(is_array($barePanel) && (string) ($barePanel['name'] ?? '') === '' && (string) ($barePanel['contact_id'] ?? '') === '' && (string) ($barePanel['id'] ?? '') === '', 'utan kundens e-post eller telefon visas ingen kontakt');
isc(($bareBox['emails'] ?? ['x']) === [] && (string) ($bareBox['phone'] ?? 'x') === '' && (string) ($barePlace['address'] ?? 'x') === '', 'tom kundruta har ingen adress, telefon eller e-post');

$phoneOnly = [
    'id' => 'thr-wendel-phone',
    'contact_id' => 'c-david',
    'customer_name' => 'Sam Wendel',
    'customer_email' => 'saljare8@malmo.example.test',
    'messages' => [array_merge($staffFrom, ['body' => "Kunden nås på 070-765 43 21.\n"])],
];
$phonePanel = jeab_inkorg_panel_contact($phoneOnly);
isc(is_array($phonePanel) && (string) ($phonePanel['name'] ?? '') === 'Kim Telefon' && (string) ($phonePanel['contact_id'] ?? '') === 'c-kim', 'telefon i tråden väljer rätt kontakt');

$staffLinked = [
    'id' => 'thr-staff-id',
    'contact_id' => 'c-sam',
    'customer_name' => 'Sam Wendel',
    'customer_email' => 'saljare8@malmo.example.test',
    'messages' => [array_merge($staffFrom, ['body' => "E-post: kund.exempel@example.test\n"])],
];
$staffPanel = jeab_inkorg_panel_contact($staffLinked);
isc(is_array($staffPanel) && (string) ($staffPanel['name'] ?? '') === 'Kund Exempel' && (string) ($staffPanel['contact_id'] ?? '') !== 'c-sam', 'personalens kontakt blir inte kunden');

$labels = jeab_contacts_inbox_apply_labels([
    [
        'id' => 'thr-list-mail',
        'contact_id' => 'c-david',
        'customer_name' => 'Sam Wendel',
        'customer_email' => 'saljare8@malmo.example.test',
        'last' => array_merge($staffFrom, ['body' => "E-post: kund.exempel@example.test\n"]),
    ],
    [
        'id' => 'thr-list-bare',
        'contact_id' => 'c-david',
        'customer_name' => 'Sam Wendel',
        'customer_email' => 'saljare8@malmo.example.test',
        'last' => $staffFrom,
    ],
    [
        'id' => 'thr-list-phone',
        'contact_id' => 'c-david',
        'customer_name' => 'Sam Wendel',
        'customer_email' => 'saljare4@jarfalla.example.test',
        'phone' => '0707654321',
        'last' => ['from' => 'saljare4@jarfalla.example.test', 'from_name' => 'Elin'],
    ],
    [
        'id' => 'thr-external',
        'contact_id' => 'c-anna',
        'customer_name' => 'Annat Namn',
        'customer_email' => 'kopia.anna@example.test',
        'last' => ['from' => 'anna.extern@example.test', 'from_name' => 'Anna Extern'],
    ],
]);
isc((string) ($labels[0]['customer_name'] ?? '') === 'Kund Exempel' && (string) ($labels[0]['contact_id'] ?? '') === 'c-kund' && !empty($labels[0]['contact_label']), 'listan matchar kundens e-post');
isc((string) ($labels[1]['customer_name'] ?? '') === 'Sam Wendel' && empty($labels[1]['contact_label']) && (string) ($labels[1]['contact_id'] ?? '') === 'c-david', 'listan utan kundnyckel behåller avsändaren och märker ingen kontakt');
isc((string) ($labels[2]['customer_name'] ?? '') === 'Kim Telefon' && (string) ($labels[2]['contact_id'] ?? '') === 'c-kim', 'listan matchar kundens telefon');
isc((string) ($labels[3]['customer_name'] ?? '') === 'Anna Extern' && (string) ($labels[3]['contact_id'] ?? '') === 'c-anna', 'extern avsändare behåller den länkade kontakten');

$blob = json_encode([$panel, $barePanel, $phonePanel, $staffPanel, $labels], JSON_UNESCAPED_UNICODE) ?: '';
isc(!str_contains($blob, 'personnummer') && !preg_match('/\bpnr\b/i', $blob), 'svaret innehåller inget personnummer');

echo "inkorg internal sender contact: ok\n";
