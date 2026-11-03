<?php

declare(strict_types=1);

/**
 * Utgående flöden läser mottagaren från kontakten.
 * Saknad kontakt lämnar radens kopia. Tomma kontaktfält målar inte över.
 * Flaggan av är exakt kopian. send_guard ligger kvar före utskicket.
 * Påhittade rader. Inga personnummer. Inga utskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-contacts-sole-out-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
session_save_path($tmp);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=0');
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
require_once dirname(__DIR__) . '/fullmakt/lib/parties.php';
jeab_ordrar_external_live(false);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];

function og(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @param array<string, mixed> $row */
function og_save_contact(array $row): void
{
    $id = trim((string) ($row['id'] ?? ''));
    $store = jeab_contact_store();
    if (!$store instanceof JeabPdoContactStore || $id === '') {
        fwrite(STDERR, "FAIL kontaktlagret är inte SQL\n");
        exit(1);
    }
    $store->upsert($id, $row);
    unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
}

/** @return array<string, mixed> */
function og_order(string $contactId, string $name, string $email, string $phone, string $street): array
{
    return [
        'reminder_id' => 92001,
        'contact_id' => $contactId,
        'name' => $name,
        'huvudsokande' => $name,
        'customer_name' => $name,
        'email' => $email,
        'phone' => $phone,
        'address' => $street,
        'huvudsokande_address' => $street,
        'gata' => $street,
        'ort' => 'Teststad',
        'postnummer' => '99999',
        'postnr' => '999 99',
        'personnummer' => '',
        'owners' => [
            ['name' => $name, 'personnummer' => '', 'email' => $email, 'phone' => $phone, 'address' => $street],
            ['name' => 'Order Med', 'personnummer' => '', 'email' => 'order.med@example.test', 'phone' => '0701000193', 'address' => 'Medgatan 2'],
        ],
        'medsokande' => [
            ['name' => 'Order Med', 'personnummer' => '', 'email' => 'order.med@example.test', 'phone' => '0701000193', 'address' => 'Medgatan 2'],
        ],
        'kind' => 'laddbox',
        'produkt' => 'Orderprodukt',
        'natbolag' => 'E.ON',
        'fastighetsbeteckning' => 'ORDER 1:2',
        'pris' => '15000',
        'company' => 'jarfalla',
    ];
}

function og_fn(string $path, string $fn): string
{
    $code = (string) file_get_contents($path);
    $start = strpos($code, 'function ' . $fn . '(');
    og($start !== false, $fn . ' finns i ' . basename($path));
    $next = strpos($code, "\nfunction ", $start + 10);

    return $next === false ? substr($code, $start) : substr($code, $start, $next - $start);
}

$store = jeab_store();
og($store instanceof JeabPdoStore, 'sqlite-lagret finns');
jeab_ensure_contacts_tables($store->pdo(), 'sqlite');
og(jeab_kontakter_sql_is_sole_source(), 'kontakter läses från SQL');

$made = jeab_kontakt_find_or_create([
    'name' => 'Ada Sole',
    'email' => 'ada.sole@example.test',
    'phone' => '0701000101',
    'address' => 'Solgatan 12, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
og(is_array($made) && trim((string) ($made['id'] ?? '')) !== '', 'Ada Sole finns');
$soleId = (string) $made['id'];
$soleContact = jeab_kontakt_get($soleId);
og(is_array($soleContact), 'Ada Sole kan läsas');
$soleContact['street'] = 'Solgatan 12';
$soleContact['city'] = 'Teststad';
$soleContact['postal_code'] = '11122';
$owners = is_array($soleContact['owners'] ?? null) ? $soleContact['owners'] : [];
$owners[] = [
    'name' => 'Bo Med',
    'personnummer' => '',
    'contact' => false,
    'email' => 'bo.med@example.test',
    'phone' => '0701000104',
];
$soleContact['owners'] = $owners;
$soleContact['produkt'] = 'FelproduktXYZ';
$soleContact['natbolag'] = 'Vattenfall';
$soleContact['pris'] = '1';
og_save_contact($soleContact);
$solePhone = jeab_kontakt_row_phone(jeab_kontakt_get($soleId) ?: []);
og($solePhone !== '' && $solePhone !== '0701000199', 'kontaktens telefon är satt');

$madeBlank = jeab_kontakt_find_or_create([
    'name' => 'Bo Blank',
    'email' => 'bo.blank@example.test',
    'phone' => '0701000102',
    'address' => 'Tomgatan 1, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
og(is_array($madeBlank) && trim((string) ($madeBlank['id'] ?? '')) !== '', 'Bo Blank finns');
$blankId = (string) $madeBlank['id'];
$blankContact = jeab_kontakt_get($blankId);
og(is_array($blankContact), 'Bo Blank kan läsas');
$blankContact['street'] = '';
$blankContact['city'] = '';
$blankContact['postal_code'] = '';
$blankContact['address'] = '';
$blankOwners = is_array($blankContact['owners'] ?? null) ? $blankContact['owners'] : [];
foreach ($blankOwners as $i => $owner) {
    if (!is_array($owner)) {
        continue;
    }
    $owner['name'] = '';
    $owner['email'] = '';
    $owner['phone'] = '';
    $owner['personnummer'] = '';
    $blankOwners[$i] = $owner;
}
$blankContact['owners'] = $blankOwners;
og_save_contact($blankContact);

$soleOrder = og_order($soleId, 'Kopia Sole', 'kopia.sole@example.test', '0701000199', 'Kvarngatan 3');
$blankOrder = og_order($blankId, 'Kopia Blank', 'kopia.blank@example.test', '0701000198', 'Blankgatan 9');
$missingOrder = og_order('kt_finns_inte', 'Kopia Saknad', 'kopia.saknad@example.test', '0701000195', 'Saknadgatan 2');
$copyOrder = og_order('', 'Kopia Kvarn', 'kopia.kvarn@example.test', '0701000196', 'Kvarngatan 3');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$GLOBALS['_JEAB_CONTACTS_SOLE_GETS'] = 0;
$off = jeab_contacts_sole_recipient($soleOrder);
og($off['name'] === 'Kopia Sole' && $off['email'] === 'kopia.sole@example.test' && $off['phone'] === '0701000199', 'flaggan av ger kopians namn, e-post och telefon');
og($off['street'] === 'Kvarngatan 3' && $off['display'] === 'Kvarngatan 3', 'flaggan av ger kopians gata');
og(!str_contains($off['name'] . $off['email'] . $off['phone'] . $off['display'], 'Ada Sole') && !str_contains($off['email'], 'ada.sole@example.test'), 'flaggan av använder inte kontakten');
$offNames = [];
foreach ($off['owners'] as $owner) {
    $offNames[] = (string) ($owner['name'] ?? '');
}
og($offNames === ['Kopia Sole', 'Order Med'], 'flaggan av ger ordens ägarlista');
og((int) $GLOBALS['_JEAB_CONTACTS_SOLE_GETS'] === 0, 'flaggan av gör inget kontaktuppslag');
og(jeab_contacts_sole_recipient_apply($soleOrder) === $soleOrder, 'flaggan av lämnar raden oförändrad');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);

$missing = jeab_contacts_sole_recipient($missingOrder);
og($missing['name'] === 'Kopia Saknad' && $missing['email'] === 'kopia.saknad@example.test' && $missing['street'] === 'Saknadgatan 2', 'kontakt saknas ⇒ radens kopia');
og(jeab_contacts_sole_recipient_apply($missingOrder) === $missingOrder, 'saknad kontakt lämnar samma rad');
$unlinked = jeab_contacts_sole_recipient($copyOrder);
og($unlinked['email'] === 'kopia.kvarn@example.test' && $unlinked['phone'] === '0701000196', 'rad utan contact_id behåller kopian');

$blank = jeab_contacts_sole_recipient($blankOrder);
og($blank['name'] === 'Kopia Blank' && $blank['email'] === 'kopia.blank@example.test' && $blank['phone'] === '0701000198', 'blank kontakt målar inte över namn, e-post eller telefon');
og($blank['street'] === 'Blankgatan 9' && $blank['display'] === 'Blankgatan 9', 'blank kontakt målar inte över gatan');
$blankNames = [];
foreach ($blank['owners'] as $owner) {
    $blankNames[] = (string) ($owner['name'] ?? '');
}
og($blankNames === ['Kopia Blank', 'Order Med'], 'tom ägarlista faller tillbaka på ordens ägare');
$blankKept = $blankOrder;
$blankApplied = jeab_contacts_sole_recipient_apply($blankOrder);
og($blankApplied === $blankOrder && $blankOrder === $blankKept, 'blank kontakt skriver inte över raden');

$live = jeab_contacts_sole_recipient($soleOrder);
og($live['name'] === 'Ada Sole' && $live['email'] === 'ada.sole@example.test' && $live['phone'] === $solePhone, 'namn, e-post och telefon kommer från kontakten');
og($live['street'] === 'Solgatan 12' && str_contains($live['display'], 'Solgatan 12'), 'gata och visningsadress kommer från kontakten');
og($live['postal'] === '11122' && $live['city'] === 'Teststad', 'postnummer och ort kommer från kontakten');
$liveNames = [];
$liveEmails = [];
foreach ($live['owners'] as $owner) {
    $liveNames[] = (string) ($owner['name'] ?? '');
    $liveEmails[] = (string) ($owner['email'] ?? '');
}
og($liveNames === ['Ada Sole', 'Bo Med'], 'ägare kommer från kontaktens ägarlista');
og(in_array('bo.med@example.test', $liveEmails, true) && !in_array('order.med@example.test', $liveEmails, true), 'medsökande e-post kommer från kontakten');
$before = $soleOrder;
$applied = jeab_contacts_sole_recipient_apply($soleOrder);
og($soleOrder === $before, 'apply skriver inte tillbaka på orderraden');
og($applied['email'] === 'ada.sole@example.test' && $applied['huvudsokande'] === 'Ada Sole' && $applied['phone'] === $solePhone, 'apply lägger kontaktens mottagare på en kopia');
og($applied['produkt'] === 'Orderprodukt' && $applied['natbolag'] === 'E.ON' && $applied['fastighetsbeteckning'] === 'ORDER 1:2' && (string) $applied['pris'] === '15000', 'produkt, nätägare, fastighet och pris målas inte');
og((string) ($applied['personnummer'] ?? '') === '', 'personnummer på raden skrivs inte om');
$appliedMed = [];
foreach (is_array($applied['medsokande'] ?? null) ? $applied['medsokande'] : [] as $owner) {
    if (is_array($owner)) {
        $appliedMed[] = (string) ($owner['name'] ?? '');
    }
}
og($appliedMed === ['Bo Med'], 'medsökande är kontaktens övriga ägare');

$fx = jeab_ordrar_fortnox_customer_row($soleOrder);
og($fx['email'] === 'ada.sole@example.test' && $fx['huvudsokande'] === 'Ada Sole' && $fx['gata'] === 'Solgatan 12', 'Fortnox-raden läser kunden från kontakten');
og($fx['produkt'] === 'Orderprodukt' && (string) ($fx['personnummer'] ?? '') === '', 'Fortnox-raden lämnar produkt och personnummer');
og($soleOrder['email'] === 'kopia.sole@example.test', 'Fortnox-läsningen sparar inte kontakten på ordern');

$fields = jeab_ellevio_fullmakt_fields($soleOrder);
og($fields['namn'] === 'Ada Sole' && $fields['epost'] === 'ada.sole@example.test' && $fields['telefon'] === $solePhone, 'fullmaktens huvudsökande kommer från kontakten');
og(($fields['personnummer'] ?? '') === '', 'fullmaktens personnummer läses som på raden');
og(str_contains((string) ($fields['adress'] ?? ''), 'Solgatan'), 'fullmaktens adress kommer från kontakten');
$medParties = jeab_ellevio_fullmakt_med_parties($soleOrder, 'Kvarngatan 3');
og(count($medParties) === 1 && ($medParties[0]['namn'] ?? '') === 'Bo Med' && ($medParties[0]['epost'] ?? '') === 'bo.med@example.test', 'fullmaktens medsökande kommer från kontaktens ägare');
og(($medParties[0]['personnummer'] ?? '') === '', 'medsökandes personnummer lämnas tomt när det saknas');

$remind = [
    'id' => 92011,
    'title' => 'Testpåminnelse',
    'customer_name' => 'Kopia Sole',
    'email' => 'kopia.sole@example.test',
    'phone' => '0701000199',
    'address' => null,
    'assignees' => [],
    'flagged' => false,
    'tags' => [],
    'note' => '',
    'due_at' => null,
    'completed_at' => null,
    'booking_id' => null,
    'contact_id' => $soleId,
    'company' => 'jarfalla',
];
$payload = jeab_reminder_payload($remind);
og($payload['customer'] === 'Ada Sole' && $payload['email'] === 'ada.sole@example.test' && $payload['phone'] === $solePhone, 'webhookens mottagare kommer från kontakten');
og(is_string($payload['address']) && str_contains($payload['address'], 'Solgatan 12'), 'webhookens adress kommer från kontakten');
$blankRemind = $remind;
$blankRemind['contact_id'] = $blankId;
$blankRemind['customer_name'] = 'Kopia Blank';
$blankRemind['email'] = 'kopia.blank@example.test';
$blankRemind['phone'] = '0701000198';
$blankRemind['address'] = null;
$blankPayload = jeab_reminder_payload($blankRemind);
og($blankPayload['customer'] === 'Kopia Blank' && $blankPayload['email'] === 'kopia.blank@example.test' && $blankPayload['address'] === null, 'blank kontakt lämnar webhookens null och kopia');

$booking = [
    'id' => 92012,
    'customer_name' => 'Kopia Sole',
    'email' => 'kopia.sole@example.test',
    'phone' => '0701000199',
    'address' => 'Kvarngatan 3',
    'job_type' => 'service',
    'starts_at' => '2026-10-23 10:00:00',
    'ends_at' => '2026-10-23 12:00:00',
    'status' => 'booked',
    'contact_id' => $soleId,
    'company' => 'jarfalla',
    'assignees' => [],
];
$title = jeab_gp_event_title($booking);
og(str_contains($title, 'Ada Sole') && str_contains($title, 'Solgatan 12'), 'kalenderns händelse läser kunden via paint_booking');
og(str_contains(jeab_gp_contact_section($booking), 'ada.sole@example.test'), 'kalenderns kontaktsektion läser e-post från kontakten');
$bookPayload = jeab_booking_payload($booking);
og($bookPayload['customer'] === 'Ada Sole' && $bookPayload['email'] === 'ada.sole@example.test', 'bokningswebhooken läser mottagaren från kontakten');

$block = jeab_offert_kundblock($soleOrder);
og($block['name'] === 'Ada Sole' && $block['email'] === 'ada.sole@example.test' && $block['street'] === 'Solgatan 12', 'offertens kundblock kommer från kontakten');
og($block['name'] !== 'Orderprodukt', 'kundblocket tar inte produktfält');
$footer = jeab_offert_kundfooter([
    'contact_id' => $soleId,
    'name' => 'Kopia Sole',
    'address' => 'Kvarngatan 3',
    'phone' => '0701000199',
    'email' => 'kopia.sole@example.test',
]);
og(str_contains($footer, 'Ada Sole') && str_contains($footer, 'ada.sole@example.test'), 'kundfoten läser kontakten');
$lines = jeab_auto_batteri_customer_lines([
    'contact_id' => $soleId,
    'name' => 'Kopia Sole',
    'address' => 'Kvarngatan 3',
    'phone' => '0701000199',
    'email' => 'kopia.sole@example.test',
]);
og(in_array('Ada Sole', $lines, true) && in_array('ada.sole@example.test', $lines, true), 'batteri-kundraderna läser kontakten');

$GLOBALS['_JEAB_PURCHASE_LIST'] = [
    '92001' => $soleOrder + ['fullmakt_token' => 'tok-sole'],
];
$doc = [
    'token' => 'tok-sole',
    'status' => 'pending',
    'namn' => '',
    'epost' => '',
    'telefon' => '',
    'adress' => '',
    'personnummer' => '',
    'parter' => [
        'huvudsokande' => [
            'namn' => 'Kopia Sole',
            'personnummer' => '',
            'telefon' => '',
            'epost' => '',
            'adress' => 'Kvarngatan 3',
        ],
        'medsokande' => [[
            'namn' => 'Bo Med',
            'personnummer' => '',
            'telefon' => '',
            'epost' => '',
            'adress' => '',
        ]],
    ],
];
$filled = fullmakt_sole_fill($doc);
og(($filled['epost'] ?? '') === 'ada.sole@example.test' && ($filled['telefon'] ?? '') === $solePhone, 'osignerad fullmakt fyller tom e-post och telefon från kontakten');
og(($filled['personnummer'] ?? '') === '' && ($filled['parter']['huvudsokande']['personnummer'] ?? '') === '', 'fullmakten skriver inte personnummer från kontakten');
og(($filled['parter']['huvudsokande']['namn'] ?? '') === 'Kopia Sole', 'ifyllt namn på parten skrivs inte över');
og(($filled['parter']['medsokande'][0]['epost'] ?? '') === 'bo.med@example.test', 'medsökandes tomma e-post fylls från kontaktens ägare');
og(($filled['parter']['medsokande'][0]['personnummer'] ?? '') === '', 'medsökandes personnummer lämnas');
$html = fullmakt_party_switch_html($filled);
og($html !== '' && !str_contains($html, 'inget telefonnummer'), 'partbytet ser kontaktens telefon och e-post');
$signed = $doc;
$signed['status'] = 'signed';
$signed['epost'] = '';
$signedOut = fullmakt_sole_fill($signed);
og(($signedOut['epost'] ?? '') === '', 'signerat dokument lämnas orört');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$offAgain = jeab_contacts_sole_recipient($soleOrder);
og($offAgain['name'] === 'Kopia Sole' && $offAgain['email'] === 'kopia.sole@example.test', 'flaggan av igen är kopian');
og(!str_contains(jeab_gp_event_title($booking), 'Ada Sole') && str_contains(jeab_gp_event_title($booking), 'Kopia Sole'), 'kalendern är kopian när flaggan är av');
$offPayload = jeab_reminder_payload($remind);
og($offPayload['customer'] === 'Kopia Sole' && $offPayload['email'] === 'kopia.sole@example.test' && $offPayload['address'] === null, 'webhooken är kopian när flaggan är av');
$offFields = jeab_ellevio_fullmakt_fields($soleOrder);
og($offFields['namn'] === 'Kopia Sole' && $offFields['epost'] === 'kopia.sole@example.test', 'fullmakten är kopian när flaggan är av');
$offFooter = jeab_offert_kundfooter([
    'contact_id' => $soleId,
    'name' => 'Kopia Sole',
    'email' => 'kopia.sole@example.test',
    'phone' => '0701000199',
    'address' => 'Kvarngatan 3',
]);
og(str_contains($offFooter, 'Kopia Sole') && !str_contains($offFooter, 'ada.sole@example.test'), 'kundfoten är kopian när flaggan är av');

$root = dirname(__DIR__);
$smsSend = og_fn($root . '/src/sms.php', 'jeab_sms_send');
$smsGuard = strpos($smsSend, 'send_guard_check');
$smsCurl = strpos($smsSend, 'curl_init');
og($smsGuard !== false && $smsCurl !== false && $smsGuard < $smsCurl, 'send_guard anropas före SMS-utskicket');
$mailSend = og_fn($root . '/src/system_mail.php', 'jeab_system_mail_send');
$mailGuard = strpos($mailSend, 'send_guard_check');
$mailTransport = strpos($mailSend, 'jeab_email_send');
og($mailGuard !== false && $mailTransport !== false && $mailGuard < $mailTransport, 'send_guard anropas före mejlutskicket');
$hsSend = og_fn($root . '/src/sms.php', 'jeab_email_send');
$hsGuard = strpos($hsSend, 'send_guard_check');
$hsCurl = strpos($hsSend, 'curl_init');
og($hsGuard !== false && $hsCurl !== false && $hsGuard < $hsCurl, 'send_guard anropas före Help Scout-utskicket');
$customerMail = og_fn($root . '/src/sms.php', 'jeab_booking_send_customer_mail');
$readAt = strpos($customerMail, 'jeab_sms_shown_booking');
$sendAt = strpos($customerMail, 'jeab_system_mail_send');
og($readAt !== false && $sendAt !== false && $readAt < $sendAt, 'mottagaren läses före mejlanropet och ersätter inte send_guard');
$tick = og_fn($root . '/src/sms.php', 'jeab_sms_tick_run');
$tickRead = strpos($tick, 'jeab_sms_shown_booking');
$tickSend = strpos($tick, 'jeab_sms_send(');
og($tickRead !== false && $tickSend !== false && $tickRead < $tickSend, 'SMS-mottagaren läses före jeab_sms_send');
$saveSent = og_fn($root . '/src/sms.php', 'jeab_sms_save_sent');
og(!str_contains($saveSent, 'jeab_contacts_sole_recipient'), 'skickat-flaggan läser inte om mottagaren');

/** @param array<string, mixed> $row */
function og_owner_field(array $row, string $name, string $field): string
{
    $want = jeab_contacts_sole_name_key($name);
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        if (jeab_contacts_sole_name_key((string) ($owner['name'] ?? '')) === $want) {
            return trim((string) ($owner[$field] ?? ''));
        }
    }

    return '';
}

/** @return array<string, mixed> */
function og_pnr_contact(string $name, string $email, string $phone, string $pnr, string $address): array
{
    $made = jeab_kontakt_find_or_create([
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'address' => 'Testgatan 1, 111 22 Teststad',
        'city' => 'Teststad',
        'postal_code' => '11122',
    ], false);
    og(is_array($made) && trim((string) ($made['id'] ?? '')) !== '', $name . ' finns');
    $id = (string) $made['id'];
    $contact = jeab_kontakt_get($id);
    og(is_array($contact), $name . ' kan läsas');
    $contact['owners'] = [[
        'name' => $name,
        'personnummer' => $pnr,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
    ]];
    og_save_contact($contact);

    return jeab_kontakt_get($id) ?: [];
}

/** @param array<string, mixed> $owners @return array<string, mixed> */
function og_pnr_order(string $contactId, string $name, string $email, string $phone, array $owners): array
{
    return [
        'reminder_id' => 92031,
        'contact_id' => $contactId,
        'name' => $name,
        'huvudsokande' => $name,
        'customer_name' => $name,
        'email' => $email,
        'phone' => $phone,
        'address' => 'Testgatan 1',
        'personnummer' => '',
        'owners' => $owners,
        'kind' => 'laddbox',
        'produkt' => 'Orderprodukt',
        'company' => 'jarfalla',
    ];
}

$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);

$dubbelA = '199013410001';
$dubbelB = '199013410002';
$enDash = '901340-0001';
$enPlain = '9013400001';
$enTwelve = '199013400001';

$dubbel = og_pnr_contact('Åsa Dubbel', 'asa.dubbel@example.test', '0701000301', '', '');
$dubbelId = (string) ($dubbel['id'] ?? '');
$dubbelOrder = og_pnr_order($dubbelId, 'Åsa Dubbel', 'asa.dubbel@example.test', '0701000301', [
    ['name' => 'Åsa Dubbel', 'personnummer' => $dubbelA, 'address' => 'Adress Ett'],
    ['name' => 'åsa  dubbel', 'personnummer' => $dubbelB, 'address' => 'Adress Tva'],
]);
$dubbelApplied = jeab_contacts_sole_recipient_apply($dubbelOrder);
og(!empty($dubbelApplied['pnr_name_ambiguous']), 'två lika namn flaggar raden');
og(og_owner_field($dubbelApplied, 'Åsa Dubbel', 'personnummer') === '', 'två lika namn lämnar personnummer tomt');
og(og_owner_field($dubbelApplied, 'Åsa Dubbel', 'address') === '', 'två lika namn lämnar adressen tom');
og((string) ($dubbelApplied['personnummer'] ?? '') === '', 'flaggan skriver inte personnummer på orderraden');

$en = og_pnr_contact('Åsa En', 'asa.en@example.test', '0701000302', '', '');
$enId = (string) ($en['id'] ?? '');
$enOrder = og_pnr_order($enId, 'Åsa En', 'asa.en@example.test', '0701000302', [
    ['name' => 'åsa  en', 'personnummer' => $enDash, 'address' => 'Enagatan 1'],
]);
$enApplied = jeab_contacts_sole_recipient_apply($enOrder);
$enPnr = og_owner_field($enApplied, 'Åsa En', 'personnummer');
og(empty($enApplied['pnr_name_ambiguous']), 'ett namn flaggas inte');
og($enPnr !== '' && $enPnr === jeab_pnr_normalize($enDash), 'ett namn fyller personnummer');
og(og_owner_field($enApplied, 'Åsa En', 'address') === 'Enagatan 1', 'ett namn fyller adressen');
$enKey = jeab_contacts_sole_pnr_key($enPnr);
og($enKey !== '' && $enKey === jeab_contacts_sole_pnr_key($enDash) && $enKey === jeab_contacts_sole_pnr_key($enPlain) && $enKey === jeab_contacts_sole_pnr_key($enTwelve), 'bindestreck och sekelsiffra matchar');

$stored = og_pnr_contact('Åsa Lagrat', 'asa.lagrat@example.test', '0701000303', $enTwelve, 'Lagradgatan 4');
$storedId = (string) ($stored['id'] ?? '');
$storedOrder = og_pnr_order($storedId, 'Åsa Lagrat', 'asa.lagrat@example.test', '0701000303', [
    ['name' => 'Åsa Lagrat', 'personnummer' => $enDash, 'address' => 'Annan gata 2'],
]);
$storedApplied = jeab_contacts_sole_recipient_apply($storedOrder);
$storedRead = og_owner_field($storedApplied, 'Åsa Lagrat', 'personnummer');
$storedAgain = jeab_kontakt_get($storedId);
$storedKept = '';
foreach (is_array($storedAgain['owners'] ?? null) ? $storedAgain['owners'] : [] as $owner) {
    if (is_array($owner)) {
        $storedKept = (string) ($owner['personnummer'] ?? '');
    }
}
og($storedKept === $enTwelve, 'läsning skriver inte om lagrat format');
og($storedRead === jeab_pnr_normalize($enTwelve) && jeab_contacts_sole_pnr_key($storedRead) === $enKey, 'läst nummer matchar bindestreck och sekel');

$noll = og_pnr_contact('Åsa Noll', 'asa.noll@example.test', '0701000304', '', '');
$nollOrder = og_pnr_order((string) ($noll['id'] ?? ''), 'Åsa Noll', 'asa.noll@example.test', '0701000304', [
    ['name' => 'Annan Person', 'personnummer' => $dubbelA, 'address' => 'Annan gata 3'],
]);
$nollApplied = jeab_contacts_sole_recipient_apply($nollOrder);
og(!empty($nollApplied['pnr_name_ambiguous']) && og_owner_field($nollApplied, 'Åsa Noll', 'personnummer') === '', 'noll träffar lämnar tomt och flaggar');

$fmtTwelve = '199013400004';
$fmtHyphen = '19901340-0004';
$huvudTwelve = '199013400003';
$huvudHyphen = '19901340-0003';
og(jeab_contacts_sole_pnr_format($fmtTwelve) === $fmtHyphen, 'visning sätter bindestreck');
og(jeab_contacts_sole_pnr_format('  inte-ett-nummer ') === 'inte-ett-nummer', 'ogiltig visning lämnas trimmad');
og(jeab_contacts_sole_pnr_store_value('901340-0002') === '199013400002', 'lagring blir tolv siffror');
og(jeab_contacts_sole_pnr_store_value('  inte-ett-nummer ') === 'inte-ett-nummer', 'ogiltig lagring lämnas trimmad');
$fmt = og_pnr_contact('Nora Huvud', 'nora.huvud@example.test', '0701000311', $huvudTwelve, 'Noragatan 1');
$fmtId = (string) ($fmt['id'] ?? '');
$fmtRow = jeab_kontakt_get($fmtId);
og(is_array($fmtRow), 'Nora kan läsas');
$fmtRow['owners'] = [
    [
        'name' => 'Nora Huvud',
        'personnummer' => $huvudTwelve,
        'email' => 'nora.huvud@example.test',
        'phone' => '0701000311',
        'address' => 'Noragatan 1',
    ],
    [
        'name' => 'Nora Med',
        'personnummer' => $fmtTwelve,
        'email' => 'nora.med@example.test',
        'phone' => '0701000312',
        'address' => 'Medgatan 4',
    ],
];
og_save_contact($fmtRow);
$fmtOrder = og_pnr_order($fmtId, 'Nora Huvud', 'nora.huvud@example.test', '0701000311', [
    ['name' => 'Nora Huvud', 'personnummer' => $huvudTwelve, 'address' => 'Noragatan 1'],
    ['name' => 'Nora Med', 'personnummer' => '', 'address' => 'Medgatan 4'],
]);
$fmtOrder['fastighetsbeteckning'] = 'NORA 1:1';
$fmtOrder['reminder_id'] = 92041;
$fmtApplied = jeab_contacts_sole_recipient_apply($fmtOrder);
og(og_owner_field($fmtApplied, 'Nora Huvud', 'personnummer') === $huvudHyphen, 'apply formaterar huvudsökandens nummer');
og(og_owner_field($fmtApplied, 'Nora Med', 'personnummer') === $fmtHyphen, 'apply formaterar medsökandens nummer');
og((string) ($fmtApplied['personnummer'] ?? '') === '', 'apply skriver inte numret på orderraden');
og((string) ($fmtApplied['produkt'] ?? '') === 'Orderprodukt', 'apply lämnar produkt');
$fmtFx = jeab_ordrar_fortnox_customer_row($fmtOrder);
og(og_owner_field($fmtFx, 'Nora Med', 'personnummer') === $fmtHyphen, 'Fortnox-raden formaterar medsökande');
$fmtPeople = jeab_ordrar_fortnox_owners($fmtFx);
$fmtMed = '';
foreach ($fmtPeople as $person) {
    if (is_array($person) && (string) ($person['name'] ?? '') === 'Nora Med') {
        $fmtMed = (string) ($person['personnummer'] ?? '');
    }
}
og($fmtMed === $fmtHyphen, 'Fortnox-ägarlistan formaterar medsökande');
$fmtParties = jeab_ellevio_fullmakt_med_parties($fmtOrder, 'Noragatan 1');
$fmtPartyPnr = '';
foreach ($fmtParties as $party) {
    if (is_array($party) && (string) ($party['namn'] ?? '') === 'Nora Med') {
        $fmtPartyPnr = (string) ($party['personnummer'] ?? '');
    }
}
og($fmtPartyPnr === $fmtHyphen, 'fullmaktens medsökande formateras');
$fmtFields = jeab_ellevio_fullmakt_fields($fmtOrder);
og((string) ($fmtFields['personnummer'] ?? '') === $huvudHyphen, 'fullmaktens personnummer kommer från kontakten');
og((string) ($fmtFields['fastighetsbeteckning'] ?? '') === 'NORA 1:1', 'fullmaktens fastighet faller tillbaka på ordern när kontakten saknar den');
$prevPurchase = $GLOBALS['_JEAB_PURCHASE_LIST'] ?? null;
$GLOBALS['_JEAB_PURCHASE_LIST'] = [
    '92041' => $fmtOrder + ['fullmakt_token' => 'tok-fmt'],
];
$fmtDoc = [
    'token' => 'tok-fmt',
    'status' => 'pending',
    'namn' => '',
    'epost' => '',
    'telefon' => '',
    'adress' => '',
    'personnummer' => '',
    'parter' => [
        'huvudsokande' => [
            'namn' => '',
            'personnummer' => '',
            'telefon' => '',
            'epost' => '',
            'adress' => '',
        ],
        'medsokande' => [[
            'namn' => 'Nora Med',
            'personnummer' => '',
            'telefon' => '',
            'epost' => '',
            'adress' => '',
        ]],
    ],
];
$fmtFilled = fullmakt_sole_fill($fmtDoc);
og((string) ($fmtFilled['personnummer'] ?? '') === $huvudHyphen && (string) ($fmtFilled['parter']['huvudsokande']['personnummer'] ?? '') === $huvudHyphen, 'fullmakten läser huvudsökandens nummer från kontakten');
og((string) ($fmtFilled['parter']['medsokande'][0]['personnummer'] ?? '') === $fmtHyphen, 'fullmakten läser medsökandens nummer från kontakten');
og((string) ($fmtFilled['fastighetsbeteckning'] ?? '') === 'NORA 1:1', 'fullmakten faller tillbaka på orderns fastighet');
if ($prevPurchase === null) {
    unset($GLOBALS['_JEAB_PURCHASE_LIST']);
} else {
    $GLOBALS['_JEAB_PURCHASE_LIST'] = $prevPurchase;
}

$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
og(jeab_contacts_sole_recipient_apply($enOrder) === $enOrder, 'flaggan av fyller inte och flaggar inte');

$GLOBALS['_JEAB_PURCHASE_LIST'] = [
    '92031' => $dubbelOrder,
    '92032' => $enOrder,
    '92033' => $nollOrder,
];
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$pnrVerify = jeab_contacts_sole_verify($store);
og((int) ($pnrVerify['pnr_name_ambiguous'] ?? -1) === 2, 'sole_verify räknar tvetydiga namn');
$pnrBlob = json_encode($pnrVerify);
og(is_string($pnrBlob) && !str_contains($pnrBlob, '19901341') && !str_contains($pnrBlob, '901340') && !str_contains($pnrBlob, '19901340'), 'sole_verify lämnar bara räknaren');

og(isset($pnrVerify['paint_conflicts']['fields']['email']) && is_int($pnrVerify['paint_conflicts']['orders']), 'sole_verify har paint_conflicts per fält');
$pc = jeab_contacts_sole_paint_conflict_fields(
    ['email' => 'A@Ex.test', 'phone' => '+46 70 100 00 01', 'gata' => 'Gamla vägen 1', 'ort' => '', 'postnummer' => '111 22'],
    ['email' => 'a@ex.test', 'phone' => '0701000001', 'street' => 'Nya vägen 2', 'city' => 'Teststad', 'postal' => '11122', 'display' => '']
);
og($pc === ['gata'], 'paint_conflicts: bara gata skiljer, tomt räknas inte, format normaliseras');
$pcBlob = json_encode($pnrVerify['paint_conflicts']);
og(is_string($pcBlob) && !str_contains($pcBlob, '@') && !str_contains($pcBlob, 'vägen'), 'paint_conflicts har inga värden');

echo "contacts sole outgoing: ok\n";
