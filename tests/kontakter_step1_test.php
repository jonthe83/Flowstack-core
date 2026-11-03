<?php

declare(strict_types=1);

/**
 * Steg 1: kontakten skapas och kopplas, men påminnelse, order och Fortnox-payload
 * behåller sina egna kundfält. Inget Fortnox-anrop lämnar processen.
 */

$tmp = sys_get_temp_dir() . '/jeab-kontakter-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'rot'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';

function kt(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function kt_pnr(): string
{
    $nine = '800101123';
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $n = (int) $nine[$i];
        if ($i % 2 === 0) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
    }
    $check = (10 - ($sum % 10)) % 10;
    return '19800101-123' . $check;
}

$pnr = kt_pnr();
kt(jeab_kontakt_pnr_valid($pnr), 'giltigt personnummer klarar Luhn');
kt(!jeab_kontakt_pnr_valid('19800101-1234'), 'ogiltigt personnummer stoppas');
kt(jeab_kontakt_pnr_mask($pnr) === '800101-****', 'personnummer maskeras som ÅÅMMDD-****');
kt(!str_contains(jeab_kontakt_pnr_mask($pnr), substr(preg_replace('/\D+/', '', $pnr) ?? '', -4)), 'masken visar inte de fyra sista');

kt(jeab_kontakt_street_key('Västra Gatan 12') === jeab_kontakt_street_key('vastra  gatan 12'), 'gata+nummer ignorerar versaler, diakriter och blanksteg');
kt(jeab_kontakt_street_key('Storgatan') === '', 'gata utan nummer är ingen adressnyckel');
$parsed = jeab_kontakt_parse_address('Testgatan 12, 123 45 Teststad');
kt($parsed['street_key'] === 'testgatan 12' && $parsed['postal'] === '12345' && $parsed['city'] === 'Teststad', 'adress rad delas i gata, postnummer och ort');
$country = jeab_kontakt_parse_address('Storgatan 1, 111 22 Stockholm, Sverige');
kt($country['street_key'] === 'storgatan 1' && $country['postal'] === '11122' && $country['city'] === 'Stockholm', 'ort tappar , Sverige');
kt(jeab_kontakt_city_name('Göteborg, Sweden') === 'Göteborg', 'Sweden i slutet tas bort');
kt(jeab_kontakt_names_same('S N', 'K F S N') && jeab_kontakt_names_same('J K', 'E J K'), 'förnamn som är en delmängd och samma efternamn är samma person');
kt(jeab_kontakt_names_same('D O', 'D O C') && jeab_kontakt_names_same('D O C', 'D O'), 'dubbelt efternamn med samma förnamn är samma person');
kt(jeab_kontakt_names_same("O'C", 'O C') && jeab_kontakt_names_same('O C', 'O-C') && jeab_kontakt_names_same("O'C", 'O-C'), 'apostrof och bindestreck jämförs som mellanslag');
kt(jeab_kontakt_names_same('D O', "D O'C") && jeab_kontakt_names_same("Maja O'Connor", 'Maja O Connor') && jeab_kontakt_names_same("Maja O'Connor", 'Maja O-Connor'), 'dubbelt efternamn med apostrof är samma person');
kt(!jeab_kontakt_names_same('D O', 'D C'), 'olika efternamn är två personer');
kt(!jeab_kontakt_names_same('U', 'U H'), 'ensamt förnamn matchar inte utan samma adress eller kanal');
kt(jeab_kontakt_firstname_only_same('U', 'U H') && !jeab_kontakt_firstname_only_same('H', 'U H'), 'ensamt förnamn pekar på det längre namnets förnamn');
kt(jeab_kontakt_names_same('Åsa Ström', 'asa strom'), 'namnjämförelse ignorerar versaler och diakriter');
kt(!jeab_kontakt_names_same('Bo Berg', 'Caj Berg'), 'samma efternamn med olika förnamn är två personer');
kt(jeab_kontakt_city_name('Järfälla kommun') === 'Järfälla' && jeab_kontakt_city_name('Järfälla kommun, Sverige') === 'Järfälla', 'kommun och Sverige tas bort från orten');
kt(jeab_kontakt_street_key('Norrskensvägen 12a') === jeab_kontakt_street_key('Norrskensvägen 12 a'), '12a och 12 a är samma adressnyckel');
$ljKey = jeab_kontakt_street_key('Ljusterö huvuds väg 23');
kt($ljKey !== '' && $ljKey === jeab_kontakt_street_key('Ljusteröhuvudsväg 23') && $ljKey === jeab_kontakt_street_key('Ljusterö-huvudsväg 23'), 'mellanslag och bindestreck i gatunamnet ignoreras');
kt(jeab_kontakt_street_key('Bokstavsvägen 12 a') === jeab_kontakt_street_key('Bokstavsvägen 12a') && jeab_kontakt_street_key('Bokstavsvägen 12') !== jeab_kontakt_street_key('Bokstavsvägen 12 a'), 'husnummer och bokstav jämförs, 12 är inte 12 a');
$norr = jeab_kontakt_parse_address('Norrskensvägen 12 a, Järfälla kommun');
kt($norr['street_key'] === 'norrskensvagen 12a' && $norr['city'] === 'Järfälla', '12 a och Järfälla kommun normaliseras');
kt(jeab_kontakt_postal('17770 9') === '17770' && jeab_kontakt_postal('177 70 9') === '17770', 'lös siffra efter postnumret faller bort');
$brokenPostal = jeab_kontakt_postal_scan('177709');
kt(jeab_kontakt_postal('177709') === '' && !empty($brokenPostal['invalid']), 'hopklistrat postnummer är ogiltigt');
$brokenAddr = jeab_kontakt_parse_address('Brokengatan 5, 177709 Järfälla');
kt($brokenAddr['street_key'] === 'brokengatan 5' && $brokenAddr['postal'] === '' && !empty($brokenAddr['postal_invalid']) && $brokenAddr['city'] === 'Järfälla', 'trasigt postnummer delas inte som nyckel');
kt(jeab_kontakt_is_po_box('Box 123') && jeab_kontakt_is_po_box('P.O. Box 5') && !jeab_kontakt_is_po_box('Norrskensvägen 12'), 'boxadress känns igen');
kt(jeab_kontakt_nonperson_name('Solcellsutbildning') && jeab_kontakt_nonperson_name('Nordisk Kompetens') && jeab_kontakt_nonperson_name('Onlinestudier Sverige') && !jeab_kontakt_nonperson_name('Exempel Bolaget AB'), 'kurs är inte en person, bolag på AB är en företagskontakt');
kt(!jeab_kontakt_nonperson_name('Anna Alm') && !jeab_kontakt_nonperson_name('Kursdeltagare Holm'), 'ett personnamn är kvar');
kt(jeab_kontakt_company_name('Exempel Bolaget AB') && jeab_kontakt_company_name('BRF Solen') && jeab_kontakt_company_name('Firman HB') && jeab_kontakt_company_name('Bostadsrättsföreningen Eken'), 'AB, BRF, HB och bostadsrättsförening är bolag');
kt(!jeab_kontakt_company_name('Anna Alm') && !jeab_kontakt_company_name('Habo Lind'), 'ett personnamn är inte ett bolag');
foreach (['Kund', 'Kundnamn', 'Test', 'hej', 'sdfg', 'asdf', 'qwer', 'Ff har'] as $junkName) {
    kt(jeab_kontakt_name_is_garbage($junkName), 'skräpnamn ' . $junkName);
}
foreach (['Bo Ek', 'Alva Lind', 'Ng Li', 'S N', 'Lars Lagerkvist'] as $keepName) {
    kt(!jeab_kontakt_name_is_garbage($keepName) && !jeab_kontakt_source_is_test(['name' => $keepName, 'email' => 'ada@post.se', 'street' => 'Almvägen 1']), 'behåller ' . $keepName);
}
kt(jeab_kontakt_text_is_internal('Filmning på lagret') && jeab_kontakt_job_is_internal('internt') && jeab_kontakt_job_is_internal('filmning'), 'interna namn och bokningstyper känns igen');
kt(!jeab_kontakt_text_is_internal('Lars Lagerkvist') && !jeab_kontakt_text_is_internal('Internationell Kund') && !jeab_kontakt_job_is_internal('laddbox'), 'ett vanligt namn och en kundbokning är inte intern');
kt(jeab_kontakt_platform_name('Kim på Leadkit') && jeab_kontakt_platform_name('Sam på Weply'), 'kvarvarande plattformsnamn tas bort');
kt(jeab_kontakt_phone('070-111 22 33') === '+46701112233' && jeab_kontakt_phone('+46700000003') === jeab_kontakt_phone('0790-066 822'), 'telefon jämförs som E.164');
kt(jeab_kontakt_name_longer('K F S N', 'S N') && jeab_kontakt_name_longer('E J K', 'J K'), 'det längre namnet behålls');

$sqlite = new PDO('sqlite:' . $tmp . '/cols.sqlite');
jeab_migrate_sqlite($sqlite);
$names = [];
foreach ($sqlite->query('PRAGMA table_info(reminders)') as $col) {
    $names[] = (string) $col['name'];
}
kt(in_array('contact_id', $names, true), 'reminders.contact_id finns');
$bnames = [];
foreach ($sqlite->query('PRAGMA table_info(bookings)') as $col) {
    $bnames[] = (string) $col['name'];
}
kt(in_array('contact_id', $bnames, true), 'bookings.contact_id finns');

$store = jeab_store();
$beforeContacts = count(jeab_kontakter_load());
$manual = jeab_create_reminder($store, 'Manuell Test', jeab_today_start(), 'anteckning', null, [
    'customer' => 'Manuell Test',
    'email' => 'manuell@example.com',
    'phone' => '0700000001',
    'address' => 'Annanvägen 3',
    'company' => 'jarfalla',
    'who' => 'Elin',
]);
kt(is_string($manual['contact_id'] ?? null) && str_starts_with((string) $manual['contact_id'], 'kt_'), 'manuell påminnelse får contact_id');
kt(count(jeab_kontakter_load()) === $beforeContacts + 1, 'manuell påminnelse skapar en kontakt');
$createSrc = (string) file_get_contents((new ReflectionFunction('jeab_create_reminder'))->getFileName());
$createFn = new ReflectionFunction('jeab_create_reminder');
$createBody = implode('', array_slice(file($createFn->getFileName()), $createFn->getStartLine() - 1, $createFn->getEndLine() - $createFn->getStartLine() + 1));
kt(!str_contains($createBody, 'jeab_kontakt_attach_reminder'), 'jeab_create_reminder kopplar inte själv');
unset($createSrc);

$GLOBALS['_JEAB_KONTAKT_POSTORT_LOOKUP'] = static function (string $street, string $postal): array {
    unset($postal);
    if (jeab_kontakt_street_key($street) !== 'testgatan 12') {
        return [];
    }
    return ['postal_code' => '12345', 'city' => 'Teststad', 'fastighetsbeteckning' => 'TESTEN 1:1'];
};

$thread = [
    'id' => 'lead-kt-1',
    'status' => 'open',
    'assignee' => '',
    'deleted' => false,
    'tags' => [],
    'brand' => 'jarfalla',
    'messages' => [],
    'first' => [
        'from' => 'ada@example.com',
        'from_name' => 'Ada Test',
        'subject' => 'Ny förfrågan',
        'body' => "Namn: Ada Test\nTelefon: 0701234567\nAdress: Testgatan 12\n",
        'labels' => ['type' => 'Ny lead', 'offer' => 'laddbox'],
    ],
];
$lead = jeab_lead_process($thread, true);
kt(!empty($lead['reminder_created']) && (int) ($lead['reminder'] ?? 0) > 0, 'lead skapar påminnelse');
$rem = jeab_get_reminder($store, (int) $lead['reminder']);
kt(is_array($rem), 'påminnelsen går att läsa');
kt((string) ($rem['customer_name'] ?? '') === 'Ada Test', 'påminnelsens namn är oförändrat');
kt((string) ($rem['email'] ?? '') === 'ada@example.com', 'påminnelsens e-post är oförändrad');
kt((string) ($rem['address'] ?? '') === 'Testgatan 12', 'påminnelsens adress skrivs inte om med ort');
kt((string) ($rem['thread_id'] ?? '') === 'lead-kt-1' && !str_contains((string) ($rem['note'] ?? ''), 'mail:'), 'påminnelsen länkas via ärendet');
$cid = (string) ($rem['contact_id'] ?? '');
kt($cid !== '' && str_starts_with($cid, 'kt_'), 'påminnelsen har stabilt contact_id');
$contact = jeab_kontakt_get($cid);
kt(is_array($contact) && ($contact['postal_code'] ?? '') === '12345' && ($contact['city'] ?? '') === 'Teststad', 'kontakten får postnummer och ort från uppslaget');
kt(($contact['fastighetsbeteckning'] ?? '') === 'TESTEN 1:1', 'kontakten tar med fastighetsbeteckning när uppslaget har en');
kt(jeab_kontakt_row_email($contact) === 'ada@example.com', 'kontaktpersonen har e-post');
$again = jeab_lead_process($thread, true);
kt(empty($again['reminder_created']), 'samma lead skapar inte en ny påminnelse');
kt(count(jeab_kontakter_load()) === 2, 'samma adress återanvänder kontakten');

$quoteRem = jeab_create_reminder($store, 'Bo Berg – offert', jeab_today_start(), 'mail: https://flowstack.example.test/index.php?p=inkorg&id=q1', null, [
    'customer' => 'Bo Berg',
    'email' => 'bo.berg@post.se',
    'company' => 'jarfalla',
    'who' => 'Elin',
]);
$quoteBefore = $quoteRem;
jeab_kontakt_attach_reminder($store, $quoteRem, [
    'name' => 'Bo Berg',
    'email' => 'bo.berg@post.se',
    'phone' => '0702222222',
    'address' => 'Duplicatgatan 4',
]);
$quoteAfter = jeab_get_reminder($store, (int) $quoteRem['id']);
kt((string) ($quoteAfter['customer_name'] ?? '') === (string) $quoteBefore['customer_name'], 'offertpåminnelsens namn består');
kt((string) ($quoteAfter['email'] ?? '') === 'bo.berg@post.se', 'offertpåminnelsens e-post består');
kt(($quoteAfter['address'] ?? null) === ($quoteBefore['address'] ?? null), 'offertpåminnelsens adressfält skrivs inte');
kt((string) ($quoteAfter['contact_id'] ?? '') !== '', 'offertvägen sätter contact_id');

$customer = jeab_find_or_create_customer($store, 'Bo Berg');
$now = jeab_now();
$booking = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Bo Berg',
    'email' => 'bo.berg@post.se',
    'phone' => '0702222222',
    'address' => 'Duplicatgatan 4, 234 56 Testby',
    'notes' => 'tiden ska bestå',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-11-02 09:00:00',
    'ends_at' => '2026-11-02 10:00:00',
    'status' => 'booked',
    'created_at' => $now,
    'updated_at' => $now,
]);
jeab_create_reminder($store, 'Daga Lind', jeab_today_start(), null, null, [
    'customer' => 'Daga Lind',
    'email' => 'daga.lind@post.se',
    'phone' => '0706666666',
    'address' => 'Duplicatgatan 4',
    'company' => 'jarfalla',
    'who' => 'Elin',
]);
$ada2 = jeab_create_reminder($store, 'Caj Berg', jeab_today_start(), null, null, [
    'customer' => 'Caj Berg',
    'email' => 'caj.berg@post.se',
    'phone' => '0703333333',
    'address' => 'duplicatgatan 4',
    'company' => 'jarfalla',
    'who' => 'Elin',
]);
$openOrder = [
    'reminder_id' => (int) $quoteRem['id'],
    'kind' => 'laddbox',
    'name' => 'Bo Berg',
    'email' => 'bo.berg@post.se',
    'phone' => '0702222222',
    'address' => 'Duplicatgatan 4',
    'postnummer' => '23456',
    'ort' => 'Testby',
    'personnummer' => $pnr,
    'fastighetsbeteckning' => 'DUP 1:1',
    'owners' => [
        ['name' => 'Bo Berg', 'personnummer' => $pnr],
        ['name' => 'Caj Berg', 'personnummer' => ''],
    ],
    'company' => 'jarfalla',
    'done' => false,
    'updated_at' => '2026-10-01 12:00:00',
];
jeab_purchase_put($openOrder);
$closedOrder = [
    'reminder_id' => (int) $ada2['id'],
    'kind' => 'laddbox',
    'name' => 'Caj Berg',
    'email' => 'caj.berg@post.se',
    'phone' => '0703333333',
    'address' => 'Duplicatgatan 4',
    'postnummer' => '23456',
    'ort' => 'Testby',
    'closed_at' => '2026-09-01 08:00:00',
    'allt_klart' => true,
    'company' => 'jarfalla',
    'updated_at' => '2026-09-01 08:00:00',
];
jeab_purchase_put($closedOrder);
$closedBefore = jeab_purchase_get((int) $ada2['id']);
$openBefore = jeab_purchase_get((int) $quoteRem['id']);
$bookingBefore = $store->getBooking((int) $booking['id']);
$inkorgPath = jeab_inkorg_dir() . '/contacts.json';
if (!is_dir(dirname($inkorgPath))) {
    mkdir(dirname($inkorgPath), 0775, true);
}
$inkorgBody = json_encode(['contacts' => [
    'in1' => [
        'name' => 'Bo Berg',
        'emails' => ['bo.berg@post.se'],
        'mobile' => '+46702222222',
        'address' => 'Duplicatgatan 4',
        'town' => 'Testby',
        'notes' => 'orörd inkorg',
    ],
]], JSON_UNESCAPED_UNICODE);
file_put_contents($inkorgPath, $inkorgBody);

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-kt-test']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_kontakter_link_dispatch(['action' => 'kontakter.link']);
kt(($denied['status'] ?? 0) === 401 && ($denied['ok'] ?? true) === false, 'kontakter.link utan token ger 401');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-kt-test';
$dry = jeab_kontakter_link_dispatch(['action' => 'kontakter.link']);
kt(($dry['status'] ?? 0) === 200 && ($dry['dry'] ?? false) === true && ($dry['created'] ?? 1) === 0, 'dry saknas betyder dry=1 och skriver inget');
kt((string) ($store->getBooking((int) $booking['id'])['contact_id'] ?? '') === '', 'dry=1 sätter inget contact_id på bokningen');
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
kt(is_string($dryJson) && !str_contains($dryJson, preg_replace('/\D+/', '', $pnr)), 'hooksvar innehåller inte personnumret');
$postalNames = [];
$dagaWithPostal = false;
$dagaOwn = false;
foreach ((array) ($dry['groups'] ?? []) as $group) {
    $key = (string) ($group['address_key'] ?? '');
    if (!str_starts_with($key, 'duplicatgatan 4')) {
        continue;
    }
    $names = [];
    foreach ((array) ($group['owners'] ?? []) as $owner) {
        if (is_array($owner)) {
            $names[] = (string) ($owner['name'] ?? '');
        }
    }
    if ($key === 'duplicatgatan 4|23456') {
        $postalNames = $names;
        $dagaWithPostal = in_array('Daga Lind', $names, true);
    } elseif (in_array('Daga Lind', $names, true) && !empty($group['needs_postnr'])) {
        $dagaOwn = true;
    }
}
sort($postalNames);
kt($postalNames === ['Bo Berg', 'Caj Berg'] && !$dagaWithPostal && $dagaOwn, 'samma gata utan postnummer och ort slås inte ihop med postnumret');

jeab_create_reminder($store, 'Ny Holm', jeab_today_start(), null, null, [
    'customer' => 'Ny Holm',
    'email' => 'ny.holm@post.se',
    'address' => 'Nygatan 15, 444 44 Nyby',
    'company' => 'jarfalla',
    'who' => 'Elin',
]);
$apply = jeab_kontakter_link_dispatch(['action' => 'kontakter.link', 'dry' => 0]);
$applyLinked = (int) ($apply['linked']['reminders'] ?? 0) + (int) ($apply['linked']['orders'] ?? 0) + (int) ($apply['linked']['bookings'] ?? 0);
kt(($apply['dry'] ?? true) === false && ((int) ($apply['created'] ?? 0) + $applyLinked) >= 1, 'dry=0 kopplar kontakter');
$bookingAfter = $store->getBooking((int) $booking['id']);
kt((string) ($bookingAfter['starts_at'] ?? '') === (string) ($bookingBefore['starts_at'] ?? '') && (string) ($bookingAfter['ends_at'] ?? '') === (string) ($bookingBefore['ends_at'] ?? ''), 'bokningstiden är oförändrad');
kt((string) ($bookingAfter['notes'] ?? '') === 'tiden ska bestå' && (string) ($bookingAfter['customer_name'] ?? '') === 'Bo Berg', 'bokningens egna fält är oförändrade');
kt((string) ($bookingAfter['contact_id'] ?? '') !== '', 'bokningen får contact_id');
$closedAfter = jeab_purchase_get((int) $ada2['id']);
kt($closedAfter === $closedBefore, 'stängd order skrivs inte om');
$openAfter = jeab_purchase_get((int) $quoteRem['id']);
kt((string) ($openAfter['contact_id'] ?? '') !== '', 'öppen order får contact_id');
$openCompare = $openAfter;
unset($openCompare['contact_id']);
$openBeforeCompare = $openBefore;
unset($openBeforeCompare['contact_id']);
kt($openCompare === $openBeforeCompare, 'öppen order behåller sina egna fält');
kt(file_get_contents($inkorgPath) === $inkorgBody, 'inkorgens kontaktfil skrivs inte');
$applyJson = json_encode($apply, JSON_UNESCAPED_UNICODE);
kt(is_string($applyJson) && !str_contains($applyJson, preg_replace('/\D+/', '', $pnr)), 'dry=0-svaret innehåller inte personnumret');
$second = jeab_kontakter_link(['dry' => 0], $store);
kt(($second['created'] ?? 1) === 0 && ($second['linked']['orders'] ?? 1) === 0 && ($second['linked']['bookings'] ?? 1) === 0, 'andra körningen är idempotent');
kt(count(jeab_kontakter_load()) === count(jeab_kontakter_load()), 'antalet kontakter står stilla');
$ids = [];
foreach (jeab_kontakter_load() as $row) {
    $ids[] = (string) ($row['id'] ?? '');
}
$againIds = $ids;
$third = jeab_kontakter_link(['dry' => '0'], $store);
$afterIds = [];
foreach (jeab_kontakter_load() as $row) {
    $afterIds[] = (string) ($row['id'] ?? '');
}
kt($afterIds === $againIds, 'kontakt-id är stabila mellan körningar');
unset($third);

$zipA = jeab_create_reminder($store, 'Zip A', jeab_today_start(), null, null, [
    'customer' => 'Zip A',
    'email' => 'zip.a@post.se',
    'address' => 'Skiljegatan 1, 111 22 Ort',
    'company' => 'jarfalla',
    'who' => 'Elin',
]);
$zipB = jeab_create_reminder($store, 'Zip B', jeab_today_start(), null, null, [
    'customer' => 'Zip B',
    'email' => 'zip.b@post.se',
    'address' => 'Skiljegatan 1, 111 33 Ort',
    'company' => 'jarfalla',
    'who' => 'Elin',
]);
$split = jeab_kontakter_link(['dry' => 1], $store);
$splitKeys = [];
foreach ((array) ($split['groups'] ?? []) as $group) {
    $members = array_merge((array) ($group['reminders'] ?? []), []);
    if (in_array((string) $zipA['id'], $members, true) || in_array((string) $zipB['id'], $members, true)) {
        $splitKeys[(string) ($group['address_key'] ?? '')] = true;
    }
}
kt(isset($splitKeys['skiljegatan 1|11122']) && isset($splitKeys['skiljegatan 1|11133']), 'samma gata med olika postnummer blir två kontakter');

$blankRem = jeab_create_reminder($store, 'Order Test', jeab_today_start(), null, null, [
    'customer' => 'Order Test',
    'email' => 'order@example.com',
    'phone' => '0704444444',
    'address' => 'Ordergatan 8, 555 55 Orderby',
    'company' => 'jarfalla',
    'who' => 'Elin',
]);
$blank = jeab_purchase_blank((int) $blankRem['id'], 'laddbox', $blankRem, $store);
kt(($blank['name'] ?? '') === 'Order Test' && ($blank['email'] ?? '') === 'order@example.com', 'ny order kopierar namn och e-post från påminnelsen');
kt(($blank['phone'] ?? '') !== '' && ($blank['address'] ?? '') !== '', 'ny order kopierar telefon och adress från påminnelsen');
kt(!array_key_exists('contact_id', $blank), 'ny order får inget contact_id av sig själv');
jeab_update_reminder($store, (int) $blankRem['id'], ['tags' => ['vunnen']]);
$started = jeab_purchase_start($store, (int) $blankRem['id'], 'laddbox');
kt(($started['name'] ?? '') === 'Order Test' && ($started['email'] ?? '') === 'order@example.com', 'purchase_start behåller kundfälten');
kt(!array_key_exists('contact_id', $started) || ($started['contact_id'] ?? null) === null || ($started['contact_id'] ?? '') === '', 'purchase_start läser inte kontakten');

$fxSrc = (new ReflectionMethod('FortnoxAPI', 'findOrCreateCustomer'))->getFileName();
$fxFn = new ReflectionMethod('FortnoxAPI', 'findOrCreateCustomer');
$fxBody = implode('', array_slice(file($fxFn->getFileName()), $fxFn->getStartLine() - 1, $fxFn->getEndLine() - $fxFn->getStartLine() + 1));
kt(!str_contains($fxBody, 'kontakt'), 'Fortnox-synken läser inte kontakter');
unset($fxSrc);
$captured = null;
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, ?array $body = null) use (&$captured): array {
    if ($method === 'POST' && $path === '/customers') {
        $captured = $body;
        return ['Customer' => ['CustomerNumber' => '99']];
    }
    return ['Customers' => []];
};
$api = new FortnoxAPI('Jarfalla');
$number = $api->findOrCreateCustomer(
    ['name' => 'Ada Alm', 'personnummer' => $pnr],
    'ada@kund.se',
    '0701234567',
    ['gata' => 'Testgatan 12', 'postnr' => '12345', 'ort' => 'Teststad']
);
kt($number === '99', 'Fortnox-stubben används, inget riktigt anrop');
$customerBody = is_array($captured['Customer'] ?? null) ? $captured['Customer'] : [];
kt(($customerBody['Name'] ?? '') === 'Ada Alm', 'Fortnox-kundens namn kommer från ordern');
kt(($customerBody['Type'] ?? '') === 'PRIVATE', 'Fortnox-kunden är privat');
kt(($customerBody['OrganisationNumber'] ?? '') === $pnr, 'Fortnox-kundens personnummer är orderns');
kt(($customerBody['Email'] ?? '') === 'ada@kund.se' && ($customerBody['Phone1'] ?? '') === '0701234567', 'Fortnox-kundens e-post och telefon är orderns');
kt(($customerBody['Address1'] ?? '') === 'Testgatan 12' && ($customerBody['ZipCode'] ?? '') === '12345' && ($customerBody['City'] ?? '') === 'Teststad', 'Fortnox-kundens adress är orderns');
kt(!array_key_exists('contact_id', $customerBody), 'Fortnox-payloaden har inget contact_id');

try {
    jeab_kontakt_save_input([
        'street' => 'Felgatan 1',
        'city' => 'Felstad',
        'owner_name_1' => 'Fel Person',
        'owner_pnr_1' => '19800101-1234',
        'contact_person' => '1',
    ]);
    kt(false, 'ogiltigt personnummer ska stoppa sparning');
} catch (InvalidArgumentException $e) {
    kt($e->getMessage() === 'Personnumret är ogiltigt.', 'feltexten säger att personnumret är ogiltigt');
    kt(!str_contains($e->getMessage(), '19800101'), 'feltexten innehåller inte numret');
}

$saved = jeab_kontakt_save_input([
    'street' => 'Spargatan 2',
    'postal_code' => '123 45',
    'city' => 'Sparstad',
    'owner_name_1' => 'Spar Test',
    'owner_pnr_1' => $pnr,
    'owner_name_2' => 'Spar Två',
    'contact_person' => '1',
    'email' => 'spar@example.com',
    'phone' => '0705555555',
]);
kt(str_starts_with((string) $saved['id'], 'kt_'), 'sparad kontakt får stabilt id');
kt(jeab_kontakt_pnr_mask((string) $saved['owners'][0]['personnummer']) !== (string) $saved['owners'][0]['personnummer'], 'lagrat personnummer är inte masken');
$public = jeab_kontakt_public($saved);
$publicJson = json_encode($public);
kt(is_string($publicJson) && !str_contains($publicJson, preg_replace('/\D+/', '', $pnr)), 'publikt kort maskerar personnumret');
kt(!empty($saved['owners'][0]['contact']) && empty($saved['owners'][1]['contact']), 'exakt en kontaktperson');
kt(($saved['owners'][1]['email'] ?? '') === '' && ($saved['owners'][0]['email'] ?? '') === 'spar@example.com', 'e-post ligger bara på kontaktpersonen');

$_GET = ['p' => 'kontakter', 'id' => (string) $saved['id']];
$error = null;
$flash = null;
$kontaktHtml = require dirname(__DIR__) . '/src/pages/kontakter.php';
$pnrDigits = preg_replace('/\D+/', '', $pnr) ?? '';
kt(is_string($kontaktHtml) && !str_contains($kontaktHtml, $pnr) && !str_contains($kontaktHtml, $pnrDigits), 'personnummer finns inte i sidans HTML');
kt(!str_contains($kontaktHtml, 'data-full'), 'sidan bäddar inte in hela numret');
kt(str_contains($kontaktHtml, 'kontakter.pnr') && str_contains($kontaktHtml, '>Visa<'), 'Visa hämtar numret från servern');
try {
    jeab_kontakt_reveal_personnummer((string) $saved['id'], '1');
    kt(false, 'personnummer kräver inloggning');
} catch (InvalidArgumentException $e) {
    kt($e->getMessage() === 'Logga in.', 'utloggad får inte personnumret');
    kt(!str_contains($e->getMessage(), substr($pnrDigits, 0, 8)), 'feltexten innehåller inte personnumret');
}
$pwFile = $tmp . '/staff-passwords.json';
file_put_contents($pwFile, json_encode([
    'secret' => bin2hex(random_bytes(16)),
    'hashes' => [['email' => 'personal@flowstack.example.test', 'hash' => password_hash('personal-kod', PASSWORD_DEFAULT)]],
]));
putenv('JEAB_PASSWORDS_FILE=' . $pwFile);
jeab_auth_data(true);
$staffHash = (string) (jeab_auth_entries(true)[0]['hash'] ?? '');
$staffCookie = jeab_auth_issue_value('personal@flowstack.example.test', $staffHash);
kt(is_string($staffCookie) && $staffCookie !== '', 'testet kan logga in personal');
$_COOKIE[JEAB_AUTH_COOKIE] = (string) $staffCookie;
$shown = jeab_kontakt_reveal_personnummer((string) $saved['id'], '1');
kt($shown === (string) $saved['owners'][0]['personnummer'], 'inloggad personal får personnumret via Visa');
kt(!str_contains(jeab_log_mask_text('visade ' . $shown), $pnrDigits), 'loggmask döljer personnummer');
unset($_COOKIE[JEAB_AUTH_COOKIE]);
$hiddenAgain = '';
try {
    $hiddenAgain = jeab_kontakt_reveal_personnummer((string) $saved['id'], '1');
} catch (InvalidArgumentException $e) {
    kt($e->getMessage() === 'Logga in.', 'utan kaka döljs numret igen');
}
kt($hiddenAgain === '', 'numret lämnas inte kvar efter utloggning');

function kt_pnr_for(string $nine): string
{
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $n = (int) $nine[$i];
        if ($i % 2 === 0) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
    }
    $check = (10 - ($sum % 10)) % 10;
    return '19' . substr($nine, 0, 6) . '-' . substr($nine, 6) . $check;
}

$pnrA = kt_pnr_for('750101001');
$pnrB = kt_pnr_for('850505001');
$pnrC = kt_pnr_for('900101001');
kt(jeab_kontakt_pnr_valid($pnrA) && jeab_kontakt_pnr_valid($pnrB) && jeab_kontakt_pnr_valid($pnrC), 'testdata har giltiga personnummer');

$blockedSamples = [
    'saljare4@jarfalla.example.test',
    'info@malmo.example.test',
    'hej@flowstack.example.test',
    'bot@mail.grokbot.com',
    'form@kalkyl.jarfalla.example.test',
    'form@wix-forms.com',
    'lead@crm.wix.com',
    'order@stores-emails.com',
    'shop@wix.com',
    'hello@wix.com',
    'leads@leadkit.com',
    'lead@nettbureau.se',
    'support@weply.se',
    'hello@getdrip.com',
    'elias@hemsol.se',
    'track@mandrillapp.com',
    'faktura@fortnox.se',
    'nyhetsbrev@kund.se',
    'kurs@teachable.com',
    'news@list-manage.com',
];
foreach ($blockedSamples as $sample) {
    kt(jeab_kontakt_address_blocked($sample), 'blockerad avsändare: ' . $sample);
}
kt(!jeab_kontakt_address_blocked('anna.alm@post.se'), 'en vanlig kundadress är inte blockerad');
kt(jeab_kontakt_platform_name('Elias på HemSol') && jeab_kontakt_platform_name('Wix Forms') && jeab_kontakt_platform_name('Formulär'), 'plattformens visningsnamn tas bort');
kt(!jeab_kontakt_platform_name('Anna Alm'), 'ett kundnamn är inte ett plattformsnamn');
kt(jeab_kontakt_source_is_test(['email' => 'test@post.se', 'street' => 'Almvägen 1', 'name' => 'Anna Alm']), 'test@ hoppas över');
kt(jeab_kontakt_source_is_test(['email' => 'ada@example.com', 'street' => 'Almvägen 1', 'name' => 'Anna Alm']), 'example.com hoppas över');
kt(jeab_kontakt_source_is_test(['email' => 'ada@post.se', 'street' => 'Testgatan 12', 'name' => 'Anna Alm']), 'test i gatan hoppas över');
kt(jeab_kontakt_source_is_test(['email' => 'ada@post.se', 'street' => 'Codytestvägen 4', 'name' => 'Anna Alm']), 'test inne i gatunamnet hoppas över');
kt(jeab_kontakt_source_is_test(['email' => 'ada@post.se', 'street' => 'Almvägen 1', 'name' => 'Anna Test']), 'test i namnet hoppas över');
kt(jeab_kontakt_source_is_test(['email' => 'ada@post.se', 'street' => 'gatan 5', 'name' => 'Anna Alm']), 'gatan 5 saknar gatunamn');
kt(jeab_kontakt_source_is_test(['email' => 'ada@post.se', 'street' => 'gatan vägen 5', 'name' => 'Anna Alm']), 'gatan vägen 5 saknar gatunamn');
kt(jeab_kontakt_source_is_test(['email' => 'ada@post.se', 'street' => 'Almvägen 1', 'name' => 't.']), 'enbokstavsnamn hoppas över');
kt(jeab_kontakt_source_is_test(['email' => 'ada@post.se', 'street' => 'Almvägen 1', 'name' => 't. 2.']), 'bokstav och siffra hoppas över');
foreach (['l.', 'f.', '0.', '1.', 'ab.', '12'] as $shortJunk) {
    kt(jeab_kontakt_name_is_garbage($shortJunk) && jeab_kontakt_source_is_test(['email' => 'ada@post.se', 'street' => 'Almvägen 1', 'name' => $shortJunk]), 'kort skräpnamn ' . $shortJunk);
}
kt(!jeab_kontakt_name_is_garbage('U') && !jeab_kontakt_name_is_garbage('Bo') && !jeab_kontakt_source_is_test(['email' => 'ada@post.se', 'street' => 'Almvägen 1', 'name' => 'U']), 'ensamt förnamn utan punkt är kvar');
foreach (['T S A', 'T S B', 'T S C', 'T B A', 'T B B', 'T B C'] as $bookingName) {
    kt(jeab_kontakt_source_is_test(['email' => 'ada@post.se', 'street' => 'Almvägen 1', 'name' => $bookingName, 'kind' => 'booking']), 'testbokning ' . $bookingName . ' hoppas över');
}
kt(!jeab_kontakt_source_is_test(['email' => 'anna.alm@post.se', 'street' => 'Almvägen 10', 'name' => 'S N']), 'initialer med efternamn är inte testdata');

$mk = static function (string $title, string $name, string $email, string $address, string $phone = '') use ($store): array {
    return jeab_create_reminder($store, $title, jeab_today_start(), null, null, [
        'customer' => $name,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'company' => 'jarfalla',
        'who' => 'Elin',
    ]);
};
$anna = $mk('Anna', 'Anna Alm', 'kontakt@jarfalla.example.test', 'Almvägen 10');
$bertil = $mk('Bertil', 'Bertil Bok', 'kontakt@jarfalla.example.test', 'Bokvägen');
$cecilia = $mk('Cecilia', 'Cecilia Ceder', 'info@malmo.example.test', '');
$david = $mk('David', 'David Dahl', 'info@malmo.example.test', '');
$elias = $mk('Elias', 'Elias på HemSol', 'elias@hemsol.se', 'Hemsolvägen 1');
$wix = $mk('Wix', 'Wix Forms', 'shop@wix.com', '');
$fortnox = $mk('Fortnox', 'Fortnox', 'faktura@fortnox.se', '');
$kurs = $mk('Kurs', 'Kursdeltagare Holm', 'kurs@foretag.se', 'Kursgatan 2, 111 22 Solna');
$sn = $mk('SN', 'S N', 'sn@post.se', 'Namngatan 9, 111 22 Solna');
$kfsn = $mk('KFSN', 'K F S N', 'kfsn@post.se', 'Namngatan 9, 111 22 Solna');
$maja = $mk('Maja', 'Maja Lind', 'maja.lind@post.se', 'Namngatan 9, 111 22 Solna');
$jk = $mk('JK', 'J K', 'jk@post.se', 'Initialgatan 3, 222 22 Lund');
jeab_purchase_put([
    'reminder_id' => (int) $jk['id'],
    'kind' => 'laddbox',
    'name' => 'J K',
    'email' => 'jk@post.se',
    'address' => 'Initialgatan 3',
    'postnummer' => '22222',
    'ort' => 'Lund',
    'personnummer' => $pnrC,
    'owners' => [
        ['name' => 'J K', 'personnummer' => $pnrC],
        ['name' => 'E J K', 'personnummer' => $pnrC],
        ['name' => 'Otto Qvist', 'personnummer' => ''],
    ],
    'company' => 'jarfalla',
    'done' => false,
    'updated_at' => '2026-10-02 12:00:00',
]);
$pnrSplit = $mk('Pnr', 'S N', 'sn.annan@post.se', 'Pnragatan 6, Uppsala');
jeab_purchase_put([
    'reminder_id' => (int) $pnrSplit['id'],
    'kind' => 'laddbox',
    'name' => 'S N',
    'email' => 'sn.annan@post.se',
    'address' => 'Pnragatan 6',
    'postnummer' => '33333',
    'ort' => 'Uppsala',
    'personnummer' => $pnrA,
    'owners' => [
        ['name' => 'S N', 'personnummer' => $pnrA],
        ['name' => 'K F S N', 'personnummer' => $pnrB],
    ],
    'company' => 'jarfalla',
    'done' => false,
    'updated_at' => '2026-10-02 12:00:00',
]);
$sthlm = $mk('Sthlm', 'Nils Nord', 'nils.nord@post.se', 'Korsgatan 2, Stockholm, Sverige');
$gbg = $mk('Gbg', 'Olga Syd', 'olga.syd@post.se', 'Korsgatan 2, Göteborg');
$lookupHit = $mk('Uppslag', 'Ulla Holm', 'ulla.holm@post.se', 'Uppslagsgatan 8');
$budgetStreet = $mk('Budget', 'Bud Holm', 'bud.holm@post.se', 'Budgatan 4');
$placeholder = $mk('Gata', 'Vera Vret', 'vera.vret@post.se', 'gatan 5');
$junkName = $mk('Bokstav', 't.', 'vera.bokstav@post.se', 'Bokstavsgatan 3');
$testBooking = $mk('Bokning', 'T S A', 'tsa@post.se', 'Bokningsgatan 6');
$testMail = $mk('Testmejl', 'Vera Vret', 'test@post.se', 'Testmejlgatan 1');
$testStreet = $mk('Testgata', 'Vera Vret', 'vera.gata@post.se', 'Codytestvägen 9');

$GLOBALS['_JEAB_KONTAKT_POSTORT_LOOKUP'] = static function (string $street, string $postal): array {
    unset($postal);
    $key = jeab_kontakt_street_key($street);
    if ($key === 'uppslagsgatan 8') {
        return ['postal_code' => '17730', 'city' => 'Järfälla', 'fastighetsbeteckning' => ''];
    }
    if ($key === 'budgatan 4') {
        return ['postal_code' => '17731', 'city' => 'Järfälla', 'fastighetsbeteckning' => ''];
    }
    if ($key === 'testgatan 12') {
        return ['postal_code' => '12345', 'city' => 'Teststad', 'fastighetsbeteckning' => 'TESTEN 1:1'];
    }
    return [];
};

$GLOBALS['_JEAB_KONTAKT_LOOKUP_BUDGET'] = 0;
$cold = jeab_kontakter_link(['dry' => 1], $store);
$coldBudget = null;
foreach ((array) ($cold['groups'] ?? []) as $group) {
    if (in_array((string) $budgetStreet['id'], (array) ($group['reminders'] ?? []), true)) {
        $coldBudget = $group;
    }
}
kt(is_array($coldBudget) && !empty($coldBudget['needs_postnr']) && ($coldBudget['postal_code'] ?? '') === '', 'uppslag som inte hinns med flaggas needs_postnr');

$GLOBALS['_JEAB_KONTAKT_LOOKUP_BUDGET'] = 2;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => [
        'lookup_provider' => 'rot',
        'internal_addresses' => ['Kontorsgatan 9, Malmö'],
        'blocked_phones' => ['070-700 00 99'],
    ],
    'people' => [
        ['id' => 'Elin', 'email' => 'saljare4@jarfalla.example.test', 'name' => 'Elin', 'company' => 'jarfalla', 'job' => 'laddbox', 'phone' => '070-111 22 33'],
        ['id' => 'ElinEk', 'email' => 'elin.ek@personal.post', 'name' => 'Elin Ek', 'company' => 'jarfalla', 'job' => null, 'phone' => '07018880001'],
    ],
    'sms' => ['from' => '+46701119999'],
], true) . ";\n");
jeab_config(true);

$noraA = $mk('Nora', 'Nora Nord', 'nora.nord@post.se', 'Norrskensvägen 12a, Järfälla');
$noraB = $mk('Nora två', 'Nora Nord', 'nora.b@post.se', 'Norrskensvägen 12 a, 17770 9 Järfälla kommun');
$doubleShort = $mk('DO', 'D O', 'd.o@post.se', 'Dubbelgatan 4, 222 22 Lund');
$doubleLong = $mk('DOC', 'D O C', 'd.o.c@post.se', 'Dubbelgatan 4, 222 22 Lund');
$firstU = $mk('U', 'U', 'u.ensam@post.se', 'Fornamnsgatan 8, 333 33 Uppsala');
$firstUH = $mk('UH', 'U H', 'u.h@post.se', 'Fornamnsgatan 8, 333 33 Uppsala');
$otherU = $mk('U annan', 'U', 'u.annan@post.se', 'Utvagen 1, 444 44 Orten');
$otherUH = $mk('UH annan', 'U H', 'u.h.annan@post.se', 'Hvagen 2, 444 44 Orten');
$pnrFirst = $mk('U pnr', 'U', 'u.pnr@post.se', 'Pnrfornamnsgatan 2, 333 44 Uppsala');
jeab_purchase_put([
    'reminder_id' => (int) $pnrFirst['id'],
    'kind' => 'laddbox',
    'name' => 'U',
    'email' => 'u.pnr@post.se',
    'address' => 'Pnrfornamnsgatan 2',
    'postnummer' => '33344',
    'ort' => 'Uppsala',
    'personnummer' => $pnrA,
    'owners' => [
        ['name' => 'U', 'personnummer' => $pnrA],
        ['name' => 'U H', 'personnummer' => $pnrB],
    ],
    'company' => 'jarfalla',
    'done' => false,
    'updated_at' => '2026-10-03 12:00:00',
]);
$switchAlm = $mk('Växel Alm', 'Switch Alm', 'switch.alm@post.se', '', '070-700 00 01');
$switchBerg = $mk('Växel Berg', 'Switch Berg', 'switch.berg@post.se', '', '+46707000001');
$switchCeder = $mk('Växel Ceder', 'Switch Ceder', 'switch.ceder@post.se', '', '0707000001');
$ownA = $mk('Eget A', 'Eget Alm', 'eget.alm@post.se', '', '+46700000003');
$ownB = $mk('Eget B', 'Eget Berg', 'eget.berg@post.se', '', '0790-066 822');
$staffA = $mk('Personal A', 'Personal Alm', 'personal.alm@post.se', '', '070-111 22 33');
$staffB = $mk('Personal B', 'Personal Berg', 'personal.berg@post.se', '', '0701112233');
$smsA = $mk('Sms A', 'Sms Alm', 'sms.alm@post.se', '', '+46701119999');
$smsB = $mk('Sms B', 'Sms Berg', 'sms.berg@post.se', '', '070-111 99 99');
$courseEdu = $mk('Utbildning', 'Solcellsutbildning', 'info@skola.post', 'Skolgatan 1, 111 11 Solna');
$courseKomp = $mk('Kompetens', 'Nordisk Kompetens', 'hej@kompetens.post', 'Kompetensgatan 2, 111 11 Solna');
$courseOnline = $mk('Online', 'Onlinestudier Sverige', 'kursinfo@online.post', '');
$companyAb = $mk('Bolag', 'Exempel Bolaget AB', 'ab@foretag.post', 'Abgatan 3, 111 11 Solna');
$poBox = $mk('Box', 'Brev Person', 'brev.person@post.se', 'Box 123, 111 22 Stockholm');
$leadkit = $mk('Leadkit', 'Kim på Leadkit', 'kim.rask@post.se', 'Plattformsgatan 3, 111 22 Solna');
$kim = $mk('Kim', 'Kim Rask', '', 'Plattformsgatan 3, 111 22 Solna');
$weply = $mk('Weply', 'Sam på Weply', 'sam@weply.se', 'Weplygatan 1, 111 22 Solna');
$office = $mk('Kontor', 'Intern Person', 'intern.person@post.se', 'Dimvägen 36, Järfälla');
$officeCfg = $mk('Kontor två', 'Kontor Person', 'kontor.person@post.se', 'Kontorsgatan 9, Malmö');
$dimCustomer = $mk('Dim kund', 'Kund Dim', 'kund.dim@post.se', 'Dimvägen 36, Göteborg');
$validPost = $mk('Post rätt', 'Brok Person', 'brok.person@post.se', 'Brokengatan 5, 177 70 Järfälla');
$badPost = $mk('Post fel', 'Annan Person', 'annan.person@post.se', 'Brokengatan 5, 177709 Järfälla');
$officeCustomer = jeab_find_or_create_customer($store, 'Intern Två');
$officeBooking = $store->insertBooking([
    'customer_id' => $officeCustomer['id'],
    'customer_name' => 'Intern Två',
    'email' => 'intern.tva@post.se',
    'phone' => '0701000098',
    'address' => 'Dimvägen 38, Järfälla kommun',
    'notes' => 'internt besök',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-11-03 09:00:00',
    'ends_at' => '2026-11-03 10:00:00',
    'status' => 'booked',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$sharedFive = [];
foreach (['Anna Alm', 'Bertil Bok', 'Cecilia Ceder', 'David Dahl', 'Erik Ek'] as $i => $sharedName) {
    $sharedFive[] = $mk('Delad ' . $i, $sharedName, 'delad.fem@post.se', '');
}
$coupleA = $mk('Par A', 'Anna Alm', 'par.alm@post.se', '');
$coupleB = $mk('Par B', 'Bertil Alm', 'par.alm@post.se', '');
$splitA = $mk('Olika A', 'Sara Ek', 'par.olika@post.se', '');
$splitB = $mk('Olika B', 'Nils Holm', 'par.olika@post.se', '');
$apoA = $mk('Apo', "Maja O'Connor", 'maja.oconnor@post.se', 'Apostrofgatan 4, 111 22 Solna');
$apoB = $mk('Apo två', 'Maja O Connor', 'maja.oconnor.b@post.se', 'Apostrofgatan 4, 111 22 Solna');
$apoC = $mk('Apo tre', 'Maja O-Connor', 'maja.oconnor.c@post.se', 'Apostrofgatan 4, 111 22 Solna');
$ljA = $mk('Ljust', 'Lina Lind', 'lina.lind@post.se', 'Ljusterö huvuds väg 23, 184 95 Ljusterö');
$ljB = $mk('Ljust två', 'Lina Lind', 'lina.lind.b@post.se', 'Ljusteröhuvudsväg 23, 184 95 Ljusterö');
$junkKund = $mk('Kund', 'Kund', 'kund.platshallare@post.se', 'Platshallargatan 1');
$junkKundnamn = $mk('Kundnamn', 'Kundnamn', 'kundnamn@post.se', 'Platshallargatan 2');
$junkSdfg = $mk('Sdfg', 'sdfg', 'sdfg@post.se', 'Platshallargatan 3');
$junkHej = $mk('Hej', 'hej', 'hej.platshallare@post.se', 'Platshallargatan 4');
$junkFf = $mk('Ff', 'Ff har', 'ff.har@post.se', 'Platshallargatan 5');
$film = $mk('Filmning', 'Filmning på lagret', 'filmning@post.se', 'Lagerlokalen 2, 177 30 Järfälla');
$brf = $mk('BRF', 'BRF Solen', 'brf.solen@post.se', 'Brfgatan 4, 111 22 Solna');
jeab_purchase_put([
    'reminder_id' => (int) $brf['id'],
    'kind' => 'laddbox',
    'name' => 'BRF Solen',
    'email' => 'brf.solen@post.se',
    'address' => 'Brfgatan 4',
    'postnummer' => '11122',
    'ort' => 'Solna',
    'owners' => [
        ['name' => 'BRF Solen', 'personnummer' => ''],
        ['name' => 'Solen', 'personnummer' => ''],
    ],
    'company' => 'jarfalla',
    'done' => false,
    'updated_at' => '2026-10-04 12:00:00',
]);
$blockPhoneA = $mk('Block A', 'Block Alm', 'block.alm@post.se', '', '070-700 00 99');
$blockPhoneB = $mk('Block B', 'Block Berg', 'block.berg@post.se', '', '0707000099');
$internCustomer = jeab_find_or_create_customer($store, 'Eva Ek');
$internBooking = $store->insertBooking([
    'customer_id' => $internCustomer['id'],
    'customer_name' => 'Eva Ek',
    'email' => 'eva.ek@post.se',
    'phone' => '0701000097',
    'address' => 'Kundgatan 15, Solna',
    'notes' => 'egen tid',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'internt',
    'starts_at' => '2026-11-04 09:00:00',
    'ends_at' => '2026-11-04 10:00:00',
    'status' => 'booked',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);

kt(jeab_kontakt_source_is_staff(['name' => 'Elin Ek', 'email' => 'annan@post.se']), 'förnamn och efternamn som personal hoppas över');
kt(!jeab_kontakt_source_is_staff(['name' => 'Elin Kund', 'email' => 'elin.kund@post.se', 'phone' => '07018880099']), 'samma förnamn som personal är en kund');
kt(jeab_kontakt_source_is_staff(['name' => 'Elin', 'email' => 'elin.ek@personal.post']), 'förnamn och personalens e-post hoppas över');
kt(jeab_kontakt_source_is_staff(['name' => 'Elin', 'phone' => '07018880001']), 'förnamn och personalens telefon hoppas över');
kt(!jeab_kontakt_source_is_staff(['name' => 'Elin', 'email' => 'elin.kund@post.se', 'phone' => '07018880099']), 'bara förnamnet utan personalens kanal är en kund');
kt(jeab_kontakt_address_is_fake(['street' => 'Niorgatan 999', 'street_key' => 'niorgatan 999']) && jeab_kontakt_address_is_fake(['street' => 'Fejkgatan 4']), 'husnummer 999 och fejk-gata hoppas över');
kt(!jeab_kontakt_address_is_fake(['street' => 'Niorgatan 12', 'street_key' => 'niorgatan 12']) && !jeab_kontakt_address_is_fake(['street' => 'Niorgatan 1999', 'street_key' => 'niorgatan 1999']), '12 och 1999 är riktiga husnummer');
foreach (['Ladda batterier med Elin', 'Möte', 'Möte med leverantör', 'Utbildning', 'Mässa'] as $eventName) {
    kt(jeab_kontakt_event_name($eventName), 'internbokning ' . $eventName);
}
kt(!jeab_kontakt_event_name('Elin Kund') && !jeab_kontakt_event_name('Solcellsutbildning') && !jeab_kontakt_event_name('Mässing Lind'), 'ett kundnamn är inte en internbokning');
$peeled = jeab_kontakt_expand_channel_splits([[
    'key' => 'email:tvingad@post.se',
    'members' => [
        ['kind' => 'reminder', 'id' => '41', 'name' => 'Sara Ek', 'email' => 'tvingad@post.se', 'phone' => '', 'street_key' => '', 'street' => '', 'postal' => '', 'city' => '', 'pnr' => '', 'extra_owners' => []],
        ['kind' => 'order', 'id' => '42', 'name' => 'Nils Holm', 'email' => 'tvingad@post.se', 'phone' => '', 'street_key' => '', 'street' => '', 'postal' => '', 'city' => '', 'pnr' => '', 'extra_owners' => []],
    ],
    'conflicts' => [],
    'needs_postnr' => false,
]]);
$peeledIds = [];
foreach ($peeled as $part) {
    foreach ((array) ($part['members'] ?? []) as $member) {
        $peeledIds[] = (string) ($member['kind'] ?? '') . ':' . (string) ($member['id'] ?? '');
    }
}
sort($peeledIds);
kt($peeledIds === ['order:42', 'reminder:41'] && count($peeled) === 2, 'en källa som efternamnsregeln delar bort får en egen grupp');
$eventPrep = jeab_kontakt_prepare_sources([
    ['kind' => 'booking', 'id' => 'm1', 'name' => 'Möte', 'email' => 'mote@post.se', 'phone' => '', 'street' => 'Motegatan 1', 'street_key' => 'motegatan 1', 'postal' => '11122', 'city' => 'Solna', 'title' => '', 'job_type' => 'laddbox', 'extra_owners' => []],
    ['kind' => 'booking', 'id' => 'u1', 'name' => 'Utbildning', 'email' => 'utb@post.se', 'phone' => '', 'street' => 'Motegatan 2', 'street_key' => 'motegatan 2', 'postal' => '11122', 'city' => 'Solna', 'title' => '', 'job_type' => 'laddbox', 'extra_owners' => []],
    ['kind' => 'booking', 'id' => 'ma1', 'name' => 'Mässa', 'email' => 'massa@post.se', 'phone' => '', 'street' => 'Motegatan 3', 'street_key' => 'motegatan 3', 'postal' => '11122', 'city' => 'Solna', 'title' => '', 'job_type' => 'laddbox', 'extra_owners' => []],
    ['kind' => 'reminder', 'id' => 'k1', 'name' => 'Elin Kund', 'email' => 'elin.kund.prep@post.se', 'phone' => '07015550001', 'street' => 'Kundgatan 9', 'street_key' => 'kundgatan 9', 'postal' => '11122', 'city' => 'Solna', 'title' => '', 'job_type' => '', 'extra_owners' => []],
    ['kind' => 'reminder', 'id' => 'f1', 'name' => 'Bo Ek', 'email' => 'bo.ek@post.se', 'phone' => '', 'street' => 'Fejkgatan 4', 'street_key' => 'fejkgatan 4', 'postal' => '11122', 'city' => 'Solna', 'title' => '', 'job_type' => '', 'extra_owners' => []],
]);
$eventKept = array_map(static fn(array $row): string => (string) ($row['id'] ?? ''), (array) ($eventPrep['items'] ?? []));
kt(($eventPrep['skipped_internal'] ?? -1) === 4 && $eventKept === ['k1'], 'möte, utbildning, mässa och fejk-gata hoppas över, kunden med samma förnamn är kvar');
$eventInternal = array_map(static fn(array $row): string => (string) ($row['kind'] ?? '') . ':' . (string) ($row['id'] ?? ''), (array) ($eventPrep['skipped_internal_sources'] ?? []));
sort($eventInternal);
kt($eventInternal === ['booking:m1', 'booking:ma1', 'booking:u1', 'reminder:f1'], 'överhoppad intern källa listas med typ och id');
$orderStreet = jeab_kontakt_source_from_order([
    'reminder_id' => 88002,
    'name' => 'S E',
    'email' => 'order.gata@post.se',
    'address' => 'Teststad',
    'gata' => 'Parvägen 10',
    'postnummer' => '11122',
    'ort' => 'Teststad',
]);
kt($orderStreet['street_key'] === 'parvagen 10' && $orderStreet['postal'] === '11122' && $orderStreet['city'] === 'Teststad', 'order utan gatunummer i adressraden tar gata, postnummer och ort');
$orderFields = jeab_kontakt_source_from_order([
    'reminder_id' => 88003,
    'name' => 'S E',
    'email' => 'order.falt@post.se',
    'address' => '',
    'street' => 'Faltvagen 4',
    'postal_code' => '22233',
    'city' => 'Lund',
]);
kt($orderFields['street_key'] === 'faltvagen 4' && $orderFields['postal'] === '22233' && $orderFields['city'] === 'Lund', 'orderns street, postal_code och city används när adressraden är tom');

$coupleL = $mk('Par L', 'L K', 'par.lk@post.se', 'Parvägen 10, 111 22 Teststad');
$coupleS = $mk('Par S', 'S E', 'par.se@post.se', 'Parvägen 10, 111 22 Teststad');
jeab_purchase_put([
    'reminder_id' => 88001,
    'kind' => 'laddbox',
    'name' => 'S E',
    'email' => 'par.lk@post.se',
    'address' => 'utan nummer',
    'owners' => [['name' => 'S E', 'personnummer' => '']],
    'company' => 'jarfalla',
    'done' => false,
    'updated_at' => '2026-10-05 12:00:00',
]);
jeab_purchase_put([
    'reminder_id' => 88002,
    'kind' => 'laddbox',
    'name' => 'S E',
    'email' => 'order.gata@post.se',
    'address' => 'Teststad',
    'gata' => 'Parvägen 10',
    'postnummer' => '11122',
    'ort' => 'Teststad',
    'owners' => [['name' => 'S E', 'personnummer' => '']],
    'company' => 'jarfalla',
    'done' => false,
    'updated_at' => '2026-10-05 12:00:00',
]);
$staffElin = $mk('Personal Elin', 'Elin Ek', 'elin.ek@personal.post', 'Personalgatan 1, 111 22 Solna');
$customerElin = $mk('Kund Elin', 'Elin Kund', 'elin.kund@post.se', 'Kundgatan 21, 111 22 Solna');
$staffChannel = $mk('Personal kanal', 'Elin', 'elin.ek@personal.post', 'Personalgatan 2, 111 22 Solna');
$house999 = $mk('Nio', 'Nora Nio', 'nora.nio@post.se', 'Niorgatan 999, 111 22 Solna');
$eventCustomer = jeab_find_or_create_customer($store, 'Ladda batterier med Elin');
$eventBooking = $store->insertBooking([
    'customer_id' => $eventCustomer['id'],
    'customer_name' => 'Ladda batterier med Elin',
    'email' => 'batteri.moten@post.se',
    'phone' => '0701000088',
    'address' => 'Motegatan 3, 111 22 Solna',
    'notes' => 'intern tid',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-11-05 09:00:00',
    'ends_at' => '2026-11-05 10:00:00',
    'status' => 'booked',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);

$follow = jeab_kontakter_link(['dry' => 1], $store);
kt(is_int($follow['skipped'] ?? null) && (int) $follow['skipped'] >= 1, 'torrkörningen räknar överhoppad testdata');
$followJson = json_encode($follow, JSON_UNESCAPED_UNICODE);
kt(is_string($followJson) && !str_contains($followJson, $pnrDigits) && !str_contains($followJson, preg_replace('/\D+/', '', $pnrA) ?? '') && !str_contains($followJson, preg_replace('/\D+/', '', $pnrB) ?? '') && !str_contains($followJson, preg_replace('/\D+/', '', $pnrC) ?? ''), 'torrkörningen innehåller inga personnummer');

$byReminder = [];
foreach ((array) ($follow['groups'] ?? []) as $group) {
    foreach ((array) ($group['reminders'] ?? []) as $rid) {
        $byReminder[(string) $rid] = $group;
    }
}
kt(!isset($byReminder[(string) $placeholder['id']]) && !isset($byReminder[(string) $junkName['id']]) && !isset($byReminder[(string) $testBooking['id']]) && !isset($byReminder[(string) $testMail['id']]) && !isset($byReminder[(string) $testStreet['id']]), 'testdata saknas i grupperna');
$annaGroup = $byReminder[(string) $anna['id']] ?? null;
$bertilGroup = $byReminder[(string) $bertil['id']] ?? null;
kt(is_array($annaGroup) && is_array($bertilGroup) && ($annaGroup['address_key'] ?? '') !== ($bertilGroup['address_key'] ?? ''), 'blockerad e-post slår inte ihop två personer');
$joinedBlocked = false;
foreach ((array) ($follow['groups'] ?? []) as $group) {
    $ids = array_merge((array) ($group['reminders'] ?? []), (array) ($group['inbox_contacts'] ?? []));
    if (in_array((string) $cecilia['id'], $ids, true) && in_array((string) $david['id'], $ids, true)) {
        $joinedBlocked = true;
    }
    foreach ((array) ($group['owners'] ?? []) as $owner) {
        $ownerEmail = (string) ($owner['email'] ?? '');
        kt($ownerEmail === '' || !jeab_kontakt_address_blocked($ownerEmail), 'ägarens e-post är inte en blockerad adress');
        kt((string) ($owner['name'] ?? '') !== 'Elias på HemSol' && (string) ($owner['name'] ?? '') !== 'Wix Forms' && (string) ($owner['name'] ?? '') !== 'Fortnox', 'plattformsnamn blir inte ägare');
        kt(trim((string) ($owner['name'] ?? '')) !== '', 'varje ägare har ett namn');
        kt((string) ($owner['name'] ?? '') !== 'Kim på Leadkit' && (string) ($owner['name'] ?? '') !== 'Sam på Weply', 'plattformsägare som «X på Leadkit» tas bort');
    }
    $namedContact = false;
    foreach ((array) ($group['owners'] ?? []) as $owner) {
        if (!empty($owner['contact']) && trim((string) ($owner['name'] ?? '')) !== '') {
            $namedContact = true;
        }
    }
    kt($namedContact && count((array) ($group['owners'] ?? [])) >= 1, 'gruppen har en namngiven kontaktperson');
    $key = (string) ($group['address_key'] ?? '');
    if (str_starts_with($key, 'email:') || str_starts_with($key, 'loose:')) {
        $channel = (string) substr($key, (int) strpos($key, ':') + 1);
        kt($channel === '' || !jeab_kontakt_address_blocked($channel), 'blockerad adress är inte kontaktnyckel');
    }
}
kt(!$joinedBlocked, 'samma malmo-demo-adress grupperar inte två personer');
kt(!isset($byReminder[(string) $wix['id']]) && !isset($byReminder[(string) $fortnox['id']]), 'plattform utan egen kund blir ingen kontakt');
$kursGroup = $byReminder[(string) $kurs['id']] ?? null;
kt(is_array($kursGroup) && ($kursGroup['owners'][0]['email'] ?? '') === '', 'kursavsändare blir inte ägarens e-post');
kt(!isset($byReminder[(string) $elias['id']]), 'plattformsnamn utan kundkanal hoppas över');

$nameGroup = $byReminder[(string) $sn['id']] ?? null;
kt(is_array($nameGroup) && ($nameGroup['address_key'] ?? '') === ($byReminder[(string) $maja['id']]['address_key'] ?? ''), 'vardagsnamn och tilltalsnamn ligger på samma adress');
$nameOwners = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), (array) ($nameGroup['owners'] ?? []));
sort($nameOwners);
kt($nameOwners === ['K F S N', 'Maja Lind'], 'det längre namnet behålls och den andra ägaren tar den fria platsen');
$extraOwner = false;
foreach ((array) ($follow['conflicts'] ?? []) as $conflict) {
    if (($conflict['kind'] ?? '') === 'extra_owner' && ($conflict['address_key'] ?? '') === ($nameGroup['address_key'] ?? '')) {
        $extraOwner = true;
    }
}
kt(!$extraOwner, 'den andra ägaren flaggas inte som extra_owner');

$pnrGroup = $byReminder[(string) $pnrSplit['id']] ?? null;
$pnrOwners = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), (array) ($pnrGroup['owners'] ?? []));
sort($pnrOwners);
kt($pnrOwners === ['K F S N', 'S N'], 'olika personnummer håller isär samma namnform');
$sameGroup = $byReminder[(string) $jk['id']] ?? null;
$sameOwners = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), (array) ($sameGroup['owners'] ?? []));
sort($sameOwners);
kt($sameOwners === ['E J K', 'Otto Qvist'], 'samma personnummer slår ihop till det längre namnet och lämnar plats åt nästa ägare');

$sthlmGroup = $byReminder[(string) $sthlm['id']] ?? null;
$gbgGroup = $byReminder[(string) $gbg['id']] ?? null;
kt(is_array($sthlmGroup) && is_array($gbgGroup) && ($sthlmGroup['address_key'] ?? '') !== ($gbgGroup['address_key'] ?? ''), 'olika orter på samma gata slås inte ihop');
kt(($sthlmGroup['city'] ?? '') === 'Stockholm' && ($gbgGroup['city'] ?? '') === 'Göteborg', 'orten är normaliserad');
kt(!empty($sthlmGroup['needs_postnr']) && !empty($gbgGroup['needs_postnr']), 'adress utan postnummer flaggas needs_postnr');
$lookupGroup = $byReminder[(string) $lookupHit['id']] ?? null;
kt(is_array($lookupGroup) && ($lookupGroup['postal_code'] ?? '') === '17730' && empty($lookupGroup['needs_postnr']) && ($lookupGroup['city'] ?? '') === 'Järfälla', 'billigt uppslag fyller postnummer och ort');
$warmBudget = $byReminder[(string) $budgetStreet['id']] ?? null;
kt(is_array($warmBudget) && ($warmBudget['postal_code'] ?? '') === '17731' && empty($warmBudget['needs_postnr']), 'uppslag inom tidsgränsen fyller postnumret');

$noraGroup = $byReminder[(string) $noraA['id']] ?? null;
kt(is_array($noraGroup) && ($noraGroup['address_key'] ?? '') === ($byReminder[(string) $noraB['id']]['address_key'] ?? ''), '12a och 12 a i Järfälla kommun blir en kontakt');
kt(($noraGroup['city'] ?? '') === 'Järfälla' && ($noraGroup['postal_code'] ?? '') === '17770', 'orten tappar kommun och den lösa siffran blir 17770');
$noraOwners = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), (array) ($noraGroup['owners'] ?? []));
kt($noraOwners === ['Nora Nord'], 'Norrskensvägen har en riktig ägare');
$doubleGroup = $byReminder[(string) $doubleShort['id']] ?? null;
$doubleOwners = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), (array) ($doubleGroup['owners'] ?? []));
kt(($doubleGroup['address_key'] ?? '') === ($byReminder[(string) $doubleLong['id']]['address_key'] ?? '') && $doubleOwners === ['D O C'], 'D O och D O C är samma person');
$firstGroup = $byReminder[(string) $firstU['id']] ?? null;
$firstOwners = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), (array) ($firstGroup['owners'] ?? []));
kt(($firstGroup['address_key'] ?? '') === ($byReminder[(string) $firstUH['id']]['address_key'] ?? '') && $firstOwners === ['U H'], 'ensamt förnamn på samma adress blir det längre namnet');
kt(($byReminder[(string) $otherU['id']]['address_key'] ?? '') !== ($byReminder[(string) $otherUH['id']]['address_key'] ?? ''), 'ensamt förnamn på en annan adress förblir en egen kontakt');
$pnrFirstOwners = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), (array) ($byReminder[(string) $pnrFirst['id']]['owners'] ?? []));
sort($pnrFirstOwners);
kt($pnrFirstOwners === ['U', 'U H'], 'olika personnummer håller isär förnamn och längre namn');
foreach ([[$switchAlm, $switchBerg], [$switchAlm, $switchCeder], [$ownA, $ownB], [$staffA, $staffB], [$smsA, $smsB]] as $pair) {
    $leftKey = (string) ($byReminder[(string) $pair[0]['id']]['address_key'] ?? '');
    $rightKey = (string) ($byReminder[(string) $pair[1]['id']]['address_key'] ?? '');
    kt($leftKey !== '' && $leftKey !== $rightKey, 'delat eller eget nummer är inte en nyckel: ' . $leftKey);
}
kt(!isset($byReminder[(string) $courseEdu['id']]) && !isset($byReminder[(string) $courseKomp['id']]) && !isset($byReminder[(string) $courseOnline['id']]) && !isset($byReminder[(string) $poBox['id']]) && !isset($byReminder[(string) $weply['id']]), 'kurs, box och plattform utan kundkanal hoppas över');
$companyGroup = $byReminder[(string) $companyAb['id']] ?? null;
$companyOwners = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), (array) ($companyGroup['owners'] ?? []));
kt(is_array($companyGroup) && ($companyGroup['kind'] ?? '') === 'company' && $companyOwners === ['Exempel Bolaget AB'], 'AB blir en företagskontakt med bolagsnamnet som ägare 1');
$brfGroup = $byReminder[(string) $brf['id']] ?? null;
$brfOwners = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), (array) ($brfGroup['owners'] ?? []));
kt(is_array($brfGroup) && ($brfGroup['kind'] ?? '') === 'company' && $brfOwners === ['BRF Solen'], 'BRF blir en företagskontakt, inte två personägare');
$kimGroup = $byReminder[(string) $kim['id']] ?? null;
$kimOwners = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), (array) ($kimGroup['owners'] ?? []));
kt(is_array($kimGroup) && ($kimGroup['address_key'] ?? '') === ($byReminder[(string) $leadkit['id']]['address_key'] ?? '') && $kimOwners === ['Kim Rask'] && ($kimGroup['owners'][0]['email'] ?? '') === 'kim.rask@post.se', 'plattformsnamnets e-post följer den enda ägaren');
kt(!isset($byReminder[(string) $office['id']]) && !isset($byReminder[(string) $officeCfg['id']]), 'egen adress hoppas över');
$officeInBookings = false;
foreach ((array) ($follow['groups'] ?? []) as $group) {
    if (in_array((string) $officeBooking['id'], (array) ($group['bookings'] ?? []), true)) {
        $officeInBookings = true;
    }
}
kt(!$officeInBookings, 'bokning på Dimvägen 38 hoppas över');
kt(isset($byReminder[(string) $dimCustomer['id']]), 'Dimvägen 36 i en annan ort är en kund');
kt(($byReminder[(string) $validPost['id']]['address_key'] ?? '') !== ($byReminder[(string) $badPost['id']]['address_key'] ?? ''), 'trasigt postnummer slår inte ihop två adresser');
kt(($byReminder[(string) $validPost['id']]['postal_code'] ?? '') === '17770', 'det giltiga postnumret består');
kt(($follow['skipped_internal'] ?? -1) === 9, 'skipped_internal räknar egna adresser, personal och internbokningar, fick ' . (int) ($follow['skipped_internal'] ?? -1));
kt(($follow['skipped_nonperson'] ?? -1) === 8, 'skipped_nonperson räknar kurs, box och plattform, fick ' . (int) ($follow['skipped_nonperson'] ?? -1));
kt(($follow['skipped_shared_phone'] ?? -1) === 11, 'skipped_shared_phone räknar växel och egna nummer, fick ' . (int) ($follow['skipped_shared_phone'] ?? -1));
kt(($follow['skipped_shared_email'] ?? -1) === 5, 'skipped_shared_email räknar delad e-post, fick ' . (int) ($follow['skipped_shared_email'] ?? -1));
foreach (['phone:+46700000003', 'phone:+46701112233', 'phone:+46707000001', 'phone:+46701119999'] as $blockedKey) {
    $seen = false;
    foreach ((array) ($follow['groups'] ?? []) as $group) {
        if (($group['address_key'] ?? '') === $blockedKey) {
            $seen = true;
        }
    }
    kt(!$seen, 'blockerat nummer är inte gruppnyckel');
}
$sharedSeen = [];
foreach ((array) ($follow['groups'] ?? []) as $group) {
    $hit = [];
    foreach ($sharedFive as $row) {
        if (in_array((string) $row['id'], (array) ($group['reminders'] ?? []), true)) {
            $hit[] = (string) $row['id'];
        }
    }
    kt(count($hit) <= 1, 'delad e-post med fem namn ligger inte i samma grupp');
    if (str_starts_with((string) ($group['address_key'] ?? ''), 'email:delad.fem@post.se')) {
        $sharedSeen[] = (string) $group['address_key'];
    }
}
kt($sharedSeen === [], 'delad e-post är inte en gruppnyckel');
$coupleGroup = $byReminder[(string) $coupleA['id']] ?? null;
$coupleOwners = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), (array) ($coupleGroup['owners'] ?? []));
sort($coupleOwners);
kt(is_array($coupleGroup) && ($coupleGroup['address_key'] ?? '') === ($byReminder[(string) $coupleB['id']]['address_key'] ?? '') && $coupleOwners === ['Anna Alm', 'Bertil Alm'], 'samma efternamn på en e-post kan vara två ägare');
kt(($byReminder[(string) $splitA['id']]['address_key'] ?? '') !== ($byReminder[(string) $splitB['id']]['address_key'] ?? ''), 'olika efternamn på bara e-post blir två kontakter');
$splitOwnersA = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), (array) ($byReminder[(string) $splitA['id']]['owners'] ?? []));
$splitOwnersB = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), (array) ($byReminder[(string) $splitB['id']]['owners'] ?? []));
kt($splitOwnersA === ['Sara Ek'] && $splitOwnersB === ['Nils Holm'], 'kanalgruppen får inte ägare med olika efternamn');
$apoGroup = $byReminder[(string) $apoA['id']] ?? null;
$apoOwners = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), (array) ($apoGroup['owners'] ?? []));
kt(is_array($apoGroup) && ($apoGroup['address_key'] ?? '') === ($byReminder[(string) $apoB['id']]['address_key'] ?? '') && ($apoGroup['address_key'] ?? '') === ($byReminder[(string) $apoC['id']]['address_key'] ?? '') && count($apoOwners) === 1, 'O\'Connor, O Connor och O-Connor är en person');
$ljGroup = $byReminder[(string) $ljA['id']] ?? null;
kt(is_array($ljGroup) && ($ljGroup['address_key'] ?? '') === ($byReminder[(string) $ljB['id']]['address_key'] ?? ''), 'Ljusterö huvuds väg och Ljusteröhuvudsväg är samma adress');
kt(!isset($byReminder[(string) $junkKund['id']]) && !isset($byReminder[(string) $junkKundnamn['id']]) && !isset($byReminder[(string) $junkSdfg['id']]) && !isset($byReminder[(string) $junkHej['id']]) && !isset($byReminder[(string) $junkFf['id']]), 'Kund, Kundnamn, sdfg, hej och Ff har räknas som test');
kt(!isset($byReminder[(string) $film['id']]), 'Filmning på lagret hoppas över som intern');
$internInBookings = false;
foreach ((array) ($follow['groups'] ?? []) as $group) {
    if (in_array((string) $internBooking['id'], (array) ($group['bookings'] ?? []), true)) {
        $internInBookings = true;
    }
}
kt(!$internInBookings, 'bokningstyp internt hoppas över');
$blockLeft = (string) ($byReminder[(string) $blockPhoneA['id']]['address_key'] ?? '');
$blockRight = (string) ($byReminder[(string) $blockPhoneB['id']]['address_key'] ?? '');
kt($blockLeft !== '' && $blockLeft !== $blockRight && !str_starts_with($blockLeft, 'phone:') && !str_starts_with($blockRight, 'phone:'), 'spärrat nummer från config är inte en nyckel');

$coupleKey = (string) ($byReminder[(string) $coupleL['id']]['address_key'] ?? '');
$coupleOrderGroup = null;
$gataOrderGroup = null;
foreach ((array) ($follow['groups'] ?? []) as $group) {
    if (in_array('88001', (array) ($group['orders'] ?? []), true)) {
        $coupleOrderGroup = $group;
    }
    if (in_array('88002', (array) ($group['orders'] ?? []), true)) {
        $gataOrderGroup = $group;
    }
}
kt(is_array($coupleOrderGroup) && ($coupleOrderGroup['address_key'] ?? '') === $coupleKey && ($coupleOrderGroup['address_key'] ?? '') === ($byReminder[(string) $coupleS['id']]['address_key'] ?? ''), 'order med annat efternamn som joinar via e-post stannar på adressen');
$coupleOwnerNames = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), (array) ($coupleOrderGroup['owners'] ?? []));
sort($coupleOwnerNames);
kt($coupleOwnerNames === ['L K', 'S E'], 'adressgruppen behåller paret, högst två ägare');
$coupleSurnameSplit = false;
foreach ((array) ($follow['conflicts'] ?? []) as $conflict) {
    if (($conflict['kind'] ?? '') === 'surname_split' && ($conflict['source'] ?? '') === 'order:88001') {
        $coupleSurnameSplit = true;
    }
}
kt(!$coupleSurnameSplit, 'adressgruppen delar inte bort ordern på efternamn');
kt(is_array($gataOrderGroup) && ($gataOrderGroup['address_key'] ?? '') === $coupleKey, 'orderns gata, postnummer och ort joinar adressen innan e-post');
kt(isset($byReminder[(string) $customerElin['id']]), 'kund med personalens förnamn finns kvar');
kt(!isset($byReminder[(string) $staffElin['id']]) && !isset($byReminder[(string) $staffChannel['id']]) && !isset($byReminder[(string) $house999['id']]), 'personal, personalens kanal och husnummer 999 hoppas över');
$eventInBookings = false;
foreach ((array) ($follow['groups'] ?? []) as $group) {
    if (in_array((string) $eventBooking['id'], (array) ($group['bookings'] ?? []), true)) {
        $eventInBookings = true;
    }
}
kt(!$eventInBookings, 'bokningen Ladda batterier med Elin hoppas över');
$unlinkedIds = array_map(static fn(array $row): string => (string) ($row['kind'] ?? '') . ':' . (string) ($row['id'] ?? ''), (array) ($follow['unlinked'] ?? []));
kt(!in_array('order:88001', $unlinkedIds, true) && !in_array('order:88002', $unlinkedIds, true), 'ordrarna är inte ospårade');
$accounted = (int) ($follow['linked_sources'] ?? -1) + (int) ($follow['skipped'] ?? -1) + (int) ($follow['skipped_internal'] ?? -1) + (int) ($follow['skipped_nonperson'] ?? -1) + count((array) ($follow['unlinked'] ?? []));
kt(!empty($follow['balance_ok']) && $accounted === (int) ($follow['sources'] ?? -2), 'källor = kopplade + överhoppade per orsak + okopplade, fick ' . $accounted . ' mot ' . (int) ($follow['sources'] ?? -2));
kt((int) ($follow['sources'] ?? -1) === count(jeab_kontakt_collect_sources($store)), 'torrkörningen räknar varje insamlad källa');
$followInternalIds = array_map(static fn(array $row): string => (string) ($row['kind'] ?? '') . ':' . (string) ($row['id'] ?? ''), (array) ($follow['skipped_internal_sources'] ?? []));
kt(count($followInternalIds) === (int) ($follow['skipped_internal'] ?? -1) && in_array('reminder:' . $staffElin['id'], $followInternalIds, true) && !in_array('reminder:' . $customerElin['id'], $followInternalIds, true), 'torrkörningen listar överhoppad personal men inte kunden');

$roundJunk = [];
foreach (['l.' => 'Kort L', 'f.' => 'Kort F', '0.' => 'Kort 0', '1.' => 'Kort 1'] as $junkLabel => $junkTitle) {
    $roundCustomer = jeab_find_or_create_customer($store, $junkLabel);
    $roundJunk[$junkLabel] = $store->insertBooking([
        'customer_id' => $roundCustomer['id'],
        'customer_name' => $junkLabel,
        'email' => '',
        'phone' => '',
        'address' => '',
        'notes' => 'tiden ska bestå ' . $junkLabel,
        'assignees' => ['Elin'],
        'company' => 'jarfalla',
        'job_type' => 'laddbox',
        'starts_at' => '2026-12-01 09:00:00',
        'ends_at' => '2026-12-01 10:00:00',
        'status' => 'booked',
        'created_at' => jeab_now(),
        'updated_at' => jeab_now(),
    ]);
}
$mioCustomer = jeab_find_or_create_customer($store, 'Mio Qvist');
$mioBooking = $store->insertBooking([
    'customer_id' => $mioCustomer['id'],
    'customer_name' => 'Mio Qvist',
    'email' => '',
    'phone' => '',
    'address' => '',
    'notes' => 'namn utan kanal',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-12-02 09:00:00',
    'ends_at' => '2026-12-02 10:00:00',
    'status' => 'booked',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$inkorgBeforeRound = (string) file_get_contents($inkorgPath);
$inkorgRound = json_decode($inkorgBeforeRound, true);
$inkorgRound['contacts']['in-namn'] = ['name' => 'Ina Inkorg', 'emails' => [], 'mobile' => '', 'address' => '', 'town' => '', 'notes' => 'bara namn'];
file_put_contents($inkorgPath, json_encode($inkorgRound, JSON_UNESCAPED_UNICODE));
$inkorgRoundBody = (string) file_get_contents($inkorgPath);
$pinned = jeab_kontakt_blank(['name' => 'Join Person', 'street' => '', 'street_key' => '']);
jeab_kontakter_mutate(function (array &$contacts) use ($pinned): void {
    $contacts[(string) $pinned['id']] = $pinned;
});
$joinCustomer = jeab_find_or_create_customer($store, 'Join Person');
$joinBooking = $store->insertBooking([
    'customer_id' => $joinCustomer['id'],
    'customer_name' => 'Join Person',
    'email' => '',
    'phone' => '',
    'address' => 'Joinvägen 4, 111 22 Solna',
    'notes' => 'redan kopplad',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-12-03 09:00:00',
    'ends_at' => '2026-12-03 10:00:00',
    'status' => 'booked',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$store->saveBooking((int) $joinBooking['id'], ['contact_id' => (string) $pinned['id']]);
$joinReminder = jeab_create_reminder($store, 'Join', jeab_today_start(), null, null, [
    'customer' => 'Join Person',
    'email' => '',
    'phone' => '',
    'address' => 'Joinvägen 4, 111 22 Solna',
    'company' => 'jarfalla',
    'who' => 'Elin',
]);
$pruneJunk = jeab_kontakt_blank(['name' => 'f.', 'street' => '', 'street_key' => '']);
$pruneStaff = jeab_kontakt_blank(['name' => 'Elin Ek', 'email' => 'elin.ek@personal.post', 'street' => '', 'street_key' => '']);
$pruneMixed = jeab_kontakt_blank(['name' => 'Vera Bland', 'email' => 'vera.bland@post.se', 'street' => 'Blandgatan 6', 'street_key' => 'blandgatan 6', 'postal_code' => '11122', 'city' => 'Solna']);
jeab_kontakter_mutate(function (array &$contacts) use ($pruneJunk, $pruneStaff, $pruneMixed): void {
    $contacts[(string) $pruneJunk['id']] = $pruneJunk;
    $contacts[(string) $pruneStaff['id']] = $pruneStaff;
    $contacts[(string) $pruneMixed['id']] = $pruneMixed;
});
$pruneBookingCustomer = jeab_find_or_create_customer($store, 'f.');
$pruneBooking = $store->insertBooking([
    'customer_id' => $pruneBookingCustomer['id'],
    'customer_name' => 'f.',
    'email' => '',
    'phone' => '',
    'address' => '',
    'notes' => 'ska stå kvar',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-12-04 11:00:00',
    'ends_at' => '2026-12-04 12:00:00',
    'status' => 'booked',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$store->saveBooking((int) $pruneBooking['id'], ['contact_id' => (string) $pruneJunk['id']]);
$pruneStaffReminder = jeab_create_reminder($store, 'Personal', jeab_today_start(), null, null, [
    'customer' => 'Elin Ek',
    'email' => 'elin.ek@personal.post',
    'address' => 'Personalgatan 8, 111 22 Solna',
    'company' => 'jarfalla',
    'who' => 'Elin',
]);
$store->saveReminder((int) $pruneStaffReminder['id'], ['contact_id' => (string) $pruneStaff['id']]);
$pruneMixedReminder = jeab_create_reminder($store, 'Bland', jeab_today_start(), null, null, [
    'customer' => 'Vera Bland',
    'email' => 'vera.bland@post.se',
    'address' => 'Blandgatan 6, 111 22 Solna',
    'company' => 'jarfalla',
    'who' => 'Elin',
]);
$store->saveReminder((int) $pruneMixedReminder['id'], ['contact_id' => (string) $pruneMixed['id']]);
$pruneClosedReminder = jeab_create_reminder($store, 'Stangd', jeab_today_start(), null, null, [
    'customer' => '0.',
    'email' => '',
    'address' => '',
    'company' => 'jarfalla',
    'who' => 'Elin',
]);
$pruneClosedContact = jeab_kontakt_blank(['name' => '0.', 'street' => '', 'street_key' => '']);
jeab_kontakter_mutate(function (array &$contacts) use ($pruneClosedContact): void {
    $contacts[(string) $pruneClosedContact['id']] = $pruneClosedContact;
});
jeab_purchase_put([
    'reminder_id' => (int) $pruneClosedReminder['id'],
    'kind' => 'laddbox',
    'name' => '0.',
    'email' => '',
    'address' => '',
    'closed_at' => '2026-10-01 08:00:00',
    'allt_klart' => true,
    'company' => 'jarfalla',
    'contact_id' => (string) $pruneClosedContact['id'],
    'updated_at' => '2026-10-01 08:00:00',
]);
$closedSnap = jeab_purchase_get((int) $pruneClosedReminder['id']);
$pruneBookingSnap = $store->getBooking((int) $pruneBooking['id']);

$pass1 = jeab_kontakter_link(['dry' => 0], $store);
kt(!empty($pass1['balance_ok']), 'första körningen på fejkdata går ihop');
$mioAfter = $store->getBooking((int) $mioBooking['id']);
$mioId = (string) ($mioAfter['contact_id'] ?? '');
kt($mioId !== '' && isset(jeab_kontakter_load()[$mioId]), 'namn utan kanal får en kontakt');
$joinContact = (string) ($store->getReminder((int) $joinReminder['id'])['contact_id'] ?? '');
kt($joinContact !== '' && isset(jeab_kontakter_load()[$joinContact]), 'påminnelsen har en kontakt för adressen');
kt((string) ($store->getBooking((int) $joinBooking['id'])['contact_id'] ?? '') === (string) $pinned['id'], 'bokningens redan satta kontakt består');
kt(count(array_filter(jeab_kontakter_load(), static function ($row): bool {
    if (!is_array($row)) {
        return false;
    }
    foreach ((array) ($row['owners'] ?? []) as $owner) {
        if (is_array($owner) && in_array((string) ($owner['name'] ?? ''), ['l.', '1.'], true)) {
            return true;
        }
    }
    return false;
})) === 0, 'l. och 1. skapas inte');
foreach ($roundJunk as $junkLabel => $junkBooking) {
    kt((string) ($store->getBooking((int) $junkBooking['id'])['contact_id'] ?? '') === '', $junkLabel . ' får inget contact_id');
}
$inaCount = 0;
foreach (jeab_kontakter_load() as $row) {
    if (!is_array($row)) {
        continue;
    }
    foreach ((array) ($row['owners'] ?? []) as $owner) {
        if (is_array($owner) && (string) ($owner['name'] ?? '') === 'Ina Inkorg') {
            $inaCount++;
        }
    }
}
kt($inaCount === 1, 'inkorgens namn blir en kontakt');
$inaFile = json_decode((string) file_get_contents($inkorgPath), true);
$inaRow = is_array($inaFile['contacts']['in-namn'] ?? null) ? $inaFile['contacts']['in-namn'] : [];
$inaContact = '';
foreach (jeab_kontakter_load() as $id => $row) {
    if (!is_array($row)) {
        continue;
    }
    foreach ((array) ($row['owners'] ?? []) as $owner) {
        if (is_array($owner) && (string) ($owner['name'] ?? '') === 'Ina Inkorg') {
            $inaContact = (string) ($row['id'] ?? $id);
        }
    }
}
kt((string) ($inaRow['notes'] ?? '') === 'bara namn' && (string) ($inaRow['name'] ?? '') === 'Ina Inkorg', 'inkorgens övriga fält består');
kt($inaContact !== '' && (string) ($inaRow['contact_id'] ?? '') === $inaContact, 'inkorg utan annan källa pekar på kontakten');
$inkorgAfterLink = (string) file_get_contents($inkorgPath);
$afterPass1 = count(jeab_kontakter_load());
$pass2 = jeab_kontakter_link(['dry' => 0], $store);
kt(($pass2['created'] ?? 1) === 0 && !empty($pass2['balance_ok']), 'andra körningen skapar inget och balansen håller');
kt(count(jeab_kontakter_load()) === $afterPass1, 'antalet kontakter står stilla efter andra körningen');
kt((string) ($store->getBooking((int) $mioBooking['id'])['contact_id'] ?? '') === $mioId, 'samma kontakt-id på namnet utan kanal');
kt((string) ($mioAfter['notes'] ?? '') === 'namn utan kanal' && (string) ($store->getBooking((int) $mioBooking['id'])['starts_at'] ?? '') === '2026-12-02 09:00:00', 'bokningens egna fält består');
kt(file_get_contents($inkorgPath) === $inkorgAfterLink, 'andra körningen skriver inte inkorgens fil');

$authBackup = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$_SERVER['HTTP_AUTHORIZATION'] = '';
$pruneDenied = jeab_kontakter_prune_dispatch(['action' => 'kontakter.prune']);
$reportDenied = jeab_kontakter_link_report_dispatch(['action' => 'kontakter.link_report']);
kt(($pruneDenied['status'] ?? 0) === 401 && ($reportDenied['status'] ?? 0) === 401, 'prune och link_report utan token ger 401');
$_SERVER['HTTP_AUTHORIZATION'] = $authBackup;
$pruneDry = jeab_kontakter_prune_dispatch(['action' => 'kontakter.prune']);
kt(($pruneDry['dry'] ?? false) === true && ($pruneDry['removed'] ?? 1) === 0, 'prune utan dry betyder torrkörning');
$adaTestId = '';
foreach (jeab_kontakter_load() as $id => $row) {
    if (!is_array($row)) {
        continue;
    }
    foreach ((array) ($row['owners'] ?? []) as $owner) {
        if (is_array($owner) && (string) ($owner['name'] ?? '') === 'Ada Test') {
            $adaTestId = (string) $id;
        }
    }
}
$pruneIds = array_map(static fn(array $row): string => (string) ($row['id'] ?? ''), (array) ($pruneDry['contacts'] ?? []));
sort($pruneIds);
$keptBecauseLinked = [(string) $pruneJunk['id'], (string) $pruneStaff['id'], (string) $pruneClosedContact['id'], $adaTestId];
$linkedOverlap = array_values(array_intersect($pruneIds, $keptBecauseLinked));
kt($adaTestId !== '' && $linkedOverlap === [], 'en bokning, påminnelse, order eller lead som pekar hindrar prune, fick ' . json_encode($pruneIds, JSON_UNESCAPED_UNICODE));
kt(isset(jeab_kontakter_load()[(string) $pruneJunk['id']]) && (string) ($store->getBooking((int) $pruneBooking['id'])['contact_id'] ?? '') === (string) $pruneJunk['id'], 'torrkörningen lämnar kontakten och contact_id');
$pruneJson = json_encode($pruneDry, JSON_UNESCAPED_UNICODE);
kt(is_string($pruneJson) && !str_contains($pruneJson, $pnrDigits), 'prune-svaret innehåller inte personnummer');
$summaryReasons = [];
foreach ((array) ($pruneDry['summary'] ?? []) as $summaryRow) {
    if (!is_array($summaryRow)) {
        continue;
    }
    $summaryReasons[(string) ($summaryRow['id'] ?? '')] = (string) ($summaryRow['reason'] ?? '');
}
kt($summaryReasons === [] || (count($summaryReasons) === count($pruneIds) && !str_contains((string) $pruneJson, '@')), 'prune-loggen har id och orsak, ingen e-post');
$pruneApply = jeab_kontakter_prune(['dry' => 0], $store);
kt(($pruneApply['removed'] ?? -1) === count($pruneIds) && isset(jeab_kontakter_load()[(string) $pruneJunk['id']]) && isset(jeab_kontakter_load()[(string) $pruneStaff['id']]) && isset(jeab_kontakter_load()[(string) $pruneClosedContact['id']]) && isset(jeab_kontakter_load()[$adaTestId]), 'prune lämnar kontakter som en bokning, påminnelse, order eller lead pekar på');
kt(isset(jeab_kontakter_load()[(string) $pruneMixed['id']]), 'en kontakt med en riktig kund lämnas kvar');
$pruneBookingAfter = $store->getBooking((int) $pruneBooking['id']);
kt((string) ($pruneBookingAfter['contact_id'] ?? '') === (string) $pruneJunk['id'] && (string) ($pruneBookingAfter['customer_name'] ?? '') === 'f.' && (string) ($pruneBookingAfter['notes'] ?? '') === 'ska stå kvar' && (string) ($pruneBookingAfter['starts_at'] ?? '') === (string) ($pruneBookingSnap['starts_at'] ?? ''), 'prune lämnar contact_id på bokningen');
$closedAfterPrune = jeab_purchase_get((int) $pruneClosedReminder['id']);
$closedCompare = $closedAfterPrune;
$closedBefore = $closedSnap;
unset($closedCompare['contact_id'], $closedBefore['contact_id']);
kt($closedCompare === $closedBefore && trim((string) ($closedAfterPrune['contact_id'] ?? '')) === (string) $pruneClosedContact['id'], 'stängd order behåller sina fält och contact_id');
kt((string) ($store->getReminder((int) $pruneStaffReminder['id'])['customer_name'] ?? '') === 'Elin Ek' && (string) ($store->getReminder((int) $pruneStaffReminder['id'])['contact_id'] ?? '') === (string) $pruneStaff['id'], 'personalens påminnelse finns kvar med contact_id');

$beforeReport = count(jeab_kontakter_load());
$report = jeab_kontakter_link_report_dispatch(['action' => 'kontakter.link_report', 'dry' => 0]);
kt(($report['dry'] ?? false) === true && ($report['created'] ?? 1) === 0 && count(jeab_kontakter_load()) === $beforeReport, 'link_report skriver inget även om dry=0 skickas med');
$reportPairs = array_map(static fn(array $row): string => (string) ($row['kind'] ?? '') . ':' . (string) ($row['id'] ?? ''), (array) ($report['skipped_internal_sources'] ?? []));
kt(count($reportPairs) === (int) ($report['skipped_internal'] ?? -1) && in_array('reminder:' . $staffElin['id'], $reportPairs, true) && !in_array('reminder:' . $customerElin['id'], $reportPairs, true), 'link_report listar överhoppade interna källor med typ och id');
$reportJson = json_encode($report, JSON_UNESCAPED_UNICODE);
kt(is_string($reportJson) && !str_contains($reportJson, $pnrDigits) && !str_contains($reportJson, preg_replace('/\D+/', '', $pnrA) ?? ''), 'link_report innehåller inte personnummer');

echo "ok\n";
