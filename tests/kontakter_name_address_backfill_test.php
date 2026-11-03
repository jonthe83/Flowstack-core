<?php

declare(strict_types=1);

/**
 * Namn och adress läses från kontakten. Platshållare «Kund – produkt» och
 * e-post som namn byts när bättre uppgifter finns.
 * Inkorg, Malmö-kopia och Wix-lead skriver namn, gata, postnummer och ort.
 * kontakter.name_address_backfill är torr som standard, ångras med batch_id,
 * skickar inget och lämnar order, bokning och påminnelse orörda.
 * Påhittade personer. Inga personnummer.
 */

$tmp = sys_get_temp_dir() . '/jeab-name-address-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$GLOBALS['_JEAB_STEG4_CUTOVER'] = true;
$GLOBALS['_JEAB_LEGACY_JSON_WRITES'] = false;
$GLOBALS['_JEAB_KLASSNING_NO_SEND'] = 1;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=1');
putenv('STEG4_CUTOVER=1');
putenv('LEGACY_JSON_WRITES=0');
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
require_once dirname(__DIR__) . '/src/layout.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_GET = ['p' => 'reminders', 'co' => 'malmo', 'v' => 'all'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';

function na_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function na_shut_name(string $html): string
{
    if (preg_match('/class="item-shut-name">([^<]*)</', $html, $m) !== 1) {
        return '';
    }

    return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
}

function na_shut_addr(string $html): string
{
    if (preg_match('/class="item-shut-addr">(.*?)<\/p>/s', $html, $m) !== 1) {
        return '';
    }

    return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
}

function na_note(string $html): string
{
    if (preg_match('/<div class="item-note">(.*?)<\/div>/s', $html, $m) !== 1) {
        return '';
    }

    return html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8');
}

function na_textarea(string $html): string
{
    if (preg_match('/name="notes"[^>]*>(.*?)<\/textarea>/s', $html, $m) !== 1) {
        return '';
    }

    return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
}

$store = jeab_store();
na_ok($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();
jeab_ensure_contacts_tables($pdo, 'sqlite');
jeab_ensure_contact_id_columns($pdo, 'sqlite');
na_ok(jeab_contacts_sole(), 'CONTACTS_SOLE är på');

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-name-address']));
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_kontakter_name_address_backfill_dispatch(['action' => 'kontakter.name_address_backfill']);
na_ok(($denied['status'] ?? 0) === 401 && ($denied['ok'] ?? true) === false, 'utan token ger 401');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-name-address';
$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
na_ok(str_contains($hookSrc, "'kontakter.name_address_backfill'") && str_contains($hookSrc, 'jeab_kontakter_name_address_backfill_dispatch'), 'hook.php registrerar kontakter.name_address_backfill');

$now = jeab_now();
$make = static function (string $name, string $email, string $street = '', string $postal = '', string $city = '') use ($now): array {
    unset($now);
    $row = jeab_kontakt_find_or_create([
        'name' => $name,
        'email' => $email,
        'phone' => '0701000400',
        'address' => $street,
        'postal_code' => $postal,
        'city' => $city,
    ], false);
    na_ok(is_array($row) && trim((string) ($row['id'] ?? '')) !== '', 'kontakt ' . $email);

    return $row;
};

$ekrem = $make('Ekrem Colak', 'ekrem.colak@example.test');
$ekremId = (string) $ekrem['id'];
$ekremRow = $store->insertReminder([
    'title' => 'Kund – Batteri',
    'customer_name' => 'Kund',
    'email' => 'ekrem.colak@example.test',
    'address' => '',
    'company' => 'malmo',
    'note' => "Kund: Kund · vill ha batteri\n",
    'due_at' => '2026-10-24 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
na_ok(jeab_kontakt_stamp_reminder($store, (int) $ekremRow['id'], $ekremId), 'Ekrem är länkad');
$ekremFresh = $store->getReminder((int) $ekremRow['id']);
na_ok(is_array($ekremFresh), 'Ekrems påminnelse finns');
$ekremHtml = jeab_reminder_row($ekremFresh, false);
na_ok(na_shut_name($ekremHtml) === 'Ekrem Colak', 'kortets namn kommer från kontakten');
na_ok(!str_contains(na_shut_addr($ekremHtml), 'Kund – Batteri'), 'Kund – Batteri visas inte som adress');
$ekremOpen = jeab_reminder_row($ekremFresh, true);
na_ok(str_contains(na_note($ekremHtml), 'Kund: Ekrem Colak'), 'stängd anteckning byter platshållaren Kund');
na_ok(str_contains(na_textarea($ekremOpen), 'Kund: Kund'), 'textfältet behåller den sparade anteckningen');

$estrella = $make('Estrella Anders', 'estrellaanders@example.test', 'Frostens väg 4', '21124', 'Malmö');
$estrellaRow = $store->insertReminder([
    'title' => 'estrellaanders@example.test – Elbilsladdare',
    'customer_name' => 'estrellaanders@example.test',
    'email' => 'estrellaanders@example.test',
    'address' => 'Kund – Elbilsladdare',
    'company' => 'malmo',
    'note' => "Kund: estrellaanders@example.test\n",
    'due_at' => '2026-10-24 10:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
na_ok(jeab_kontakt_stamp_reminder($store, (int) $estrellaRow['id'], (string) $estrella['id']), 'Estrella är länkad');
$estrellaFresh = $store->getReminder((int) $estrellaRow['id']);
$estrellaShown = jeab_reminder_shown_customer($estrellaFresh);
na_ok($estrellaShown['name'] === 'Estrella Anders', 'e-post i titeln viker för kontaktens namn');
na_ok(str_contains((string) $estrellaShown['street'], 'Frostens väg 4'), 'gatan kommer från kontakten');
$estrellaHtml = jeab_reminder_row($estrellaFresh, false);
na_ok(na_shut_name($estrellaHtml) === 'Estrella Anders', 'kortet visar inte mejladressen som namn');
na_ok(!str_contains(na_shut_addr($estrellaHtml), 'Kund – Elbilsladdare'), 'produkttiteln är inte adressraden');
na_ok(str_contains(na_shut_addr($estrellaHtml), 'Frostens väg 4'), 'adressraden visar kontaktens gata');

$emptyContact = $make('Tom Visning', 'tom.visning@example.test');
jeab_kontakter_mutate(static function (array &$contacts) use ($emptyContact): void {
    $id = (string) $emptyContact['id'];
    $contacts[$id]['owners'] = [['name' => '', 'email' => 'tom.visning@example.test', 'phone' => '', 'contact' => true]];
    $contacts[$id]['street'] = '';
    $contacts[$id]['city'] = '';
    $contacts[$id]['postal_code'] = '';
});
if (function_exists('jeab_contacts_sole_cache_forget')) {
    jeab_contacts_sole_cache_forget((string) $emptyContact['id']);
}
$gap = [
    'reminder_id' => 88011,
    'contact_id' => (string) $emptyContact['id'],
    'name' => 'Lirije Hoxha',
    'huvudsokande' => 'Lirije Hoxha',
    'customer_name' => 'kund.a@example.invalid',
    'email' => 'lirije@live.se',
    'address' => 'Ängavångsgatan 79',
    'gata' => 'Ängavångsgatan 79',
];
na_ok(jeab_ordrar_name($gap) === 'Lirije Hoxha', 'orderlistan tar det sparade namnet när kontakten saknar namn');
$painted = jeab_ordrar_display_row($gap);
na_ok(($painted['name'] ?? 'x') === '' && ($painted['huvudsokande'] ?? 'x') === '', 'paint lämnar tomt kontaktnamn tomt');
$invoice = jeab_ordrar_fakturering_customer($gap);
na_ok(($invoice['name'] ?? 'x') === '', 'fakturering följer den tomma kontakten');

$emailContact = $make('Kund', 'lirije@live.se', 'Bokgatan 4', '21122', 'Lund');
jeab_kontakter_mutate(static function (array &$contacts) use ($emailContact): void {
    $id = (string) $emailContact['id'];
    $contacts[$id]['owners'][0]['name'] = 'kund.a@example.invalid';
});
if (function_exists('jeab_contacts_sole_cache_forget')) {
    jeab_contacts_sole_cache_forget((string) $emailContact['id']);
}
$emailOrder = [
    'reminder_id' => 88012,
    'contact_id' => (string) $emailContact['id'],
    'name' => 'kund.a@example.invalid',
    'huvudsokande' => 'kund.a@example.invalid',
    'customer_name' => 'kund.a@example.invalid',
    'email' => 'lirije@live.se',
    'address' => 'Ängavångsgatan 79',
];
na_ok(mb_strtolower(jeab_ordrar_name($emailOrder)) === 'lirije@live.se', 'utan bättre namn visas mejlen');

$realOrder = [
    'reminder_id' => 88013,
    'contact_id' => (string) $estrella['id'],
    'name' => 'Annat Namn',
    'huvudsokande' => 'Annat Namn',
    'customer_name' => 'Annat Namn',
    'email' => 'estrellaanders@example.test',
];
na_ok(jeab_ordrar_name($realOrder) === 'Estrella Anders', 'kontaktens riktiga namn vinner över orderkopian');

$hint = jeab_name_address_contact_hint_from_lead([
    'from' => 'forms@wix-forms.com',
    'from_name' => 'Wix Forms',
    'payload' => [
        'fields' => [
            ['label' => 'Namn', 'value' => 'Peter Mathiesen'],
            ['label' => 'Adress', 'value' => 'Ängavångsgatan 79'],
            ['label' => 'Postnummer', 'value' => '212 19'],
            ['label' => 'Ort', 'value' => 'Malmö'],
        ],
    ],
], ['name' => '', 'email' => 'peter.mathiesen@example.test', 'address' => ''], []);
na_ok($hint['name'] === 'Peter Mathiesen', 'Wix-fältet Namn läses');
na_ok($hint['street'] === 'Ängavångsgatan 79', 'Wix-fältet Adress läses');
na_ok($hint['postal_code'] === '21219', 'Wix-fältet Postnummer läses');
na_ok($hint['city'] === 'Malmö', 'Wix-fältet Ort läses');

if (function_exists('jeab_auto_settings_save')) {
    jeab_auto_settings_save([
        'auto_quote_enabled' => 0,
        'auto_trash_spam' => 0,
        'auto_quote_only_from' => '',
        'unknown_product_ask_enabled' => 0,
        'auto_send_per_hour' => 500,
        'auto_send_per_day' => 2000,
        'tpl' => function_exists('jeab_auto_quote_seed') ? jeab_auto_quote_seed() : [],
    ]);
}
$wixIn = jeab_inkorg_ingest([
    'message_id' => 'wix-peter-mathiesen@lead.example',
    'from' => 'forms@wix-forms.com',
    'from_name' => 'Wix Forms',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Nytt formulär: Batteri',
    'body_text' => "A site visitor just submitted your form Batteri\n\nNamn: Peter Mathiesen\nE-post: peter.mathiesen@gmail.com\nAdress: Ängavångsgatan 79\nPostnummer: 21219\nOrt: Malmö\n",
    'source' => 'wix',
    'company' => 'jarfalla',
    'received_at' => '2026-10-20T10:00:00+02:00',
    'preset_labels' => [
        'type' => 'Ny lead',
        'intent' => 'kop',
        'offer' => 'batteri',
        'lead' => 'eget',
        'product' => 'batteri',
        'confidence' => 100,
        'source' => 'preset',
        'question' => '',
    ],
]);
na_ok(!empty($wixIn['ok']), 'Wix-leadet tas in');
$wixTid = (string) ($wixIn['thread_id'] ?? '');
$wixThread = jeab_auto_thread_by_id($wixTid);
na_ok(is_array($wixThread), 'Wix-tråden kan läsas');
$peterId = function_exists('jeab_contacts_sole_party_id') ? jeab_contacts_sole_party_id('peter.mathiesen@gmail.com') : '';
$peter = $peterId !== '' ? jeab_kontakt_get($peterId) : null;
na_ok(is_array($peter), 'Wix-kontakten finns');
na_ok(jeab_kontakt_row_name($peter) === 'Peter Mathiesen', 'Wix-kontakten får det lästa namnet');
na_ok(str_contains((string) ($peter['street'] ?? ''), 'Ängavångsgatan 79'), 'Wix-kontakten får gatan');
na_ok(str_contains((string) ($peter['postal_code'] ?? ''), '21219'), 'Wix-kontakten får postnumret');
na_ok((string) ($peter['city'] ?? '') === 'Malmö', 'Wix-kontakten får orten');
$wixLead = jeab_lead_process($wixThread, true);
$peterAfter = jeab_kontakt_get($peterId);
na_ok(is_array($peterAfter) && jeab_kontakt_row_name($peterAfter) === 'Peter Mathiesen', 'lead-steget lämnar namnet');
na_ok(str_contains((string) ($peterAfter['postal_code'] ?? ''), '21219'), 'lead-steget lämnar postnumret');
$jfIn = jeab_inkorg_ingest([
    'message_id' => 'wix-nora-lind@lead.example',
    'from' => 'forms@wix-forms.com',
    'from_name' => 'Wix Forms',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Nytt formulär: Elbilsladdare',
    'body_text' => "A site visitor just submitted your form Elbilsladdare\n\nNamn: Nora Lind\nE-post: nora.lind@gmail.com\nAdress: Vasavägen 12\nPostnummer: 17732\nOrt: Järfälla\n",
    'source' => 'wix',
    'company' => 'jarfalla',
    'received_at' => '2026-10-20T12:00:00+02:00',
    'preset_labels' => [
        'type' => 'Ny lead',
        'intent' => 'kop',
        'offer' => 'laddbox',
        'lead' => 'eget',
        'product' => 'laddbox',
        'confidence' => 100,
        'source' => 'preset',
        'question' => '',
    ],
]);
na_ok(!empty($jfIn['ok']), 'Järfälla-leadet tas in');
$jfThread = jeab_auto_thread_by_id((string) ($jfIn['thread_id'] ?? ''));
na_ok(is_array($jfThread), 'Järfälla-tråden kan läsas');
$jfLead = is_array($jfThread) ? jeab_lead_process($jfThread, true) : [];
na_ok(!empty($jfLead['lead']) && (int) ($jfLead['reminder'] ?? 0) > 0, 'Järfälla-leadet skapar påminnelse');
$noraId = jeab_contacts_sole_party_id('nora.lind@gmail.com');
$nora = $noraId !== '' ? jeab_kontakt_get($noraId) : null;
na_ok(is_array($nora) && jeab_kontakt_row_name($nora) === 'Nora Lind', 'Järfälla-kontakten får namnet');
na_ok(str_contains((string) ($nora['street'] ?? ''), 'Vasavägen 12'), 'Järfälla-kontakten får gatan');
na_ok(str_contains((string) ($nora['postal_code'] ?? ''), '17732'), 'Järfälla-kontakten får postnumret');
na_ok((string) ($nora['city'] ?? '') === 'Järfälla', 'Järfälla-kontakten får orten');

$hsIn = jeab_inkorg_ingest([
    'message_id' => 'mhs-ekrem-copy@example.test',
    'from' => 'ekrem.copy@example.test',
    'from_name' => 'Ekrem Copy',
    'to' => 'saljare.syd@malmo.example.test',
    'subject' => 'Batteri till hemmet',
    'body_text' => "Namn: Ekrem Copy\nE-post: ekrem.copy@example.test\nAdress: Kopieringsgatan 5\nPostnummer: 21120\nOrt: Malmö\n",
    'source' => 'malmo-hs',
    'company' => 'malmo',
    'thread_key' => 'trad-ekrem-copy',
    'received_at' => '2026-10-21T11:00:00+02:00',
]);
na_ok(!empty($hsIn['ok']), 'Malmö-kopian tas in');
$hsTid = (string) ($hsIn['thread_id'] ?? 'trad-ekrem-copy');
$copy = $make('Kund', 'ekrem.copy@example.test');
$copyId = (string) $copy['id'];
jeab_inkorg_thread_write($hsTid, static function (array $t) use ($copyId): array {
    $t['contact_id'] = $copyId;
    $t['customer_email'] = 'ekrem.copy@example.test';

    return $t;
});
$filled = jeab_malmo_hs_fill($hsTid, '44001', ['jobs' => ['batteri']], 'ekrem.copy@example.test', 'Kund', $copyId);
na_ok(array_key_exists('created', $filled), 'Malmö-fyllnaden svarar');
$copyAfter = jeab_kontakt_get($copyId);
na_ok(is_array($copyAfter), 'Malmö-kontakten finns kvar');
na_ok(jeab_kontakt_row_name($copyAfter) === 'Ekrem Copy', 'Malmö-kopian skriver namnet på kontakten');
na_ok(str_contains((string) ($copyAfter['street'] ?? ''), 'Kopieringsgatan 5'), 'Malmö-kopian skriver gatan');
na_ok(str_contains((string) ($copyAfter['postal_code'] ?? ''), '21120'), 'Malmö-kopian skriver postnumret');
na_ok((string) ($copyAfter['city'] ?? '') === 'Malmö', 'Malmö-kopian skriver orten');

$older = jeab_inkorg_ingest([
    'message_id' => 'older-lirije@example.test',
    'from' => 'lirije.old@example.test',
    'from_name' => 'Gamla Namnet',
    'to' => 'saljare.syd@malmo.example.test',
    'subject' => 'Äldre formulär',
    'body_text' => "Namn: Gamla Namnet\nE-post: lirije.old@example.test\nAdress: Gamla gatan 1\nPostnummer: 21111\nOrt: Lund\n",
    'source' => 'malmo-hs',
    'company' => 'malmo',
    'thread_key' => 'trad-lirije-backfill',
    'received_at' => '2026-10-01T09:00:00+02:00',
]);
$newer = jeab_inkorg_ingest([
    'message_id' => 'newer-lirije@example.test',
    'from' => 'lirije.old@example.test',
    'from_name' => 'Lirije Hoxha',
    'to' => 'saljare.syd@malmo.example.test',
    'subject' => 'Nyare formulär',
    'body_text' => "Namn: Lirije Hoxha\nAdress: Ängavångsgatan 79\nPostnummer: 21219\nOrt: Malmö\n",
    'source' => 'malmo-hs',
    'company' => 'malmo',
    'thread_key' => 'trad-lirije-backfill',
    'received_at' => '2026-10-22T09:00:00+02:00',
]);
na_ok(!empty($older['ok']) && !empty($newer['ok']), 'två formulär ligger i samma tråd');
$backfillContact = $make('Kund', 'lirije.old@example.test');
$backfillId = (string) $backfillContact['id'];
jeab_kontakter_mutate(static function (array &$contacts) use ($backfillId): void {
    $contacts[$backfillId]['street'] = 'Kund – Elbilsladdare';
    $contacts[$backfillId]['street_key'] = '';
    $contacts[$backfillId]['postal_code'] = '';
    $contacts[$backfillId]['city'] = '';
    $contacts[$backfillId]['owners'][0]['name'] = 'kund.a@example.invalid';
});
jeab_inkorg_thread_write('trad-lirije-backfill', static function (array $t) use ($backfillId): array {
    $t['contact_id'] = $backfillId;

    return $t;
});
$backfillReminder = $store->insertReminder([
    'title' => 'Kund – Elbilsladdare',
    'customer_name' => 'kund.a@example.invalid',
    'email' => 'lirije.old@example.test',
    'address' => '',
    'company' => 'malmo',
    'thread_id' => 'trad-lirije-backfill',
    'note' => "Kund: Kund\n",
    'due_at' => '2026-10-24 12:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
na_ok(jeab_kontakt_stamp_reminder($store, (int) $backfillReminder['id'], $backfillId), 'backfill-påminnelsen är länkad');
$kept = $make('Behålls Person', 'behalls.person@example.test', 'Behållsgatan 2', '11122', 'Teststad');
$keptId = (string) $kept['id'];
$keptReminder = $store->insertReminder([
    'title' => 'Behålls',
    'customer_name' => 'Behålls Person',
    'email' => 'behalls.person@example.test',
    'address' => 'Behållsgatan 2',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 13:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
na_ok(jeab_kontakt_stamp_reminder($store, (int) $keptReminder['id'], $keptId), 'orörd påminnelse är länkad');
$customer = jeab_find_or_create_customer($store, 'Behålls Person');
$booking = $store->insertBooking([
    'customer_id' => (int) ($customer['id'] ?? 0),
    'customer_name' => 'Behålls Person',
    'email' => 'behalls.person@example.test',
    'phone' => '0701000401',
    'address' => 'Behållsgatan 2',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-11-02 09:00:00',
    'ends_at' => '2026-11-02 10:00:00',
    'status' => 'booked',
    'created_at' => $now,
    'updated_at' => $now,
]);
na_ok((int) ($booking['id'] ?? 0) > 0, 'bokningen finns');
if (function_exists('jeab_kontakt_stamp_booking')) {
    jeab_kontakt_stamp_booking($store, (int) $booking['id'], $keptId);
}
$orderReminder = $store->insertReminder([
    'title' => 'Order Behålls',
    'customer_name' => 'Behålls Person',
    'email' => 'behalls.person@example.test',
    'address' => 'Behållsgatan 2',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 14:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
na_ok(jeab_kontakt_stamp_reminder($store, (int) $orderReminder['id'], $keptId), 'orderpåminnelsen är länkad');
jeab_purchase_put([
    'reminder_id' => (int) $orderReminder['id'],
    'contact_id' => $keptId,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Behålls Person',
    'address' => 'Behållsgatan 2',
    'created_at' => $now,
]);

$beforeCounts = jeab_contacts_sole_counts($store);
$beforeLinks = jeab_contacts_sole_link_map($store);
$dry = jeab_kontakter_name_address_backfill_dispatch(['action' => 'kontakter.name_address_backfill']);
na_ok(!empty($dry['ok']) && (int) ($dry['dry'] ?? 0) === 1 && empty($dry['written']), 'dry är standard och skriver inget');
na_ok((int) ($dry['sent'] ?? 1) === 0, 'dry skickar inget');
$dryRow = null;
foreach ((array) ($dry['contacts'] ?? []) as $row) {
    if (is_array($row) && (string) ($row['id'] ?? '') === $backfillId) {
        $dryRow = $row;
    }
}
na_ok(is_array($dryRow), 'dry rapporterar kontakten');
na_ok(($dryRow['name'] ?? '') === 'Lirije Hoxha', 'dry visar det nyaste namnet');
na_ok(str_contains((string) ($dryRow['street'] ?? ''), 'Ängavångsgatan 79'), 'dry visar den nyaste gatan');
na_ok((string) ($dryRow['postal_code'] ?? '') === '21219', 'dry visar postnumret');
na_ok((string) ($dryRow['city'] ?? '') === 'Malmö', 'dry visar orten');
$untouched = jeab_kontakt_get($backfillId);
na_ok(jeab_kontakt_row_name($untouched ?: []) === 'kund.a@example.invalid', 'dry lämnar namnet');
na_ok((string) ($untouched['street'] ?? '') === 'Kund – Elbilsladdare', 'dry lämnar gatuplatshållaren');
$keptDry = jeab_kontakt_get($keptId);
na_ok(jeab_kontakt_row_name($keptDry ?: []) === 'Behålls Person', 'ett riktigt namn föreslås inte');
na_ok((string) ($keptDry['street'] ?? '') === 'Behållsgatan 2', 'en riktig gata föreslås inte');

$wet = jeab_kontakter_name_address_backfill_dispatch([
    'action' => 'kontakter.name_address_backfill',
    'dry' => 0,
    'contact_id' => $backfillId,
]);
na_ok(!empty($wet['ok']) && (int) ($wet['dry'] ?? 1) === 0 && !empty($wet['written']), 'dry=0 skriver');
na_ok((int) ($wet['sent'] ?? 1) === 0, 'skrivningen skickar inget');
na_ok(!empty($wet['counts_same']) && !empty($wet['links_same']), 'antal och länkar är oförändrade');
$batch = (string) ($wet['batch_id'] ?? '');
na_ok($batch !== '', 'batch_id följer med');
$written = jeab_kontakt_get($backfillId);
na_ok(jeab_kontakt_row_name($written ?: []) === 'Lirije Hoxha', 'mejlnamnet byts mot det nyaste namnet');
na_ok(str_contains((string) ($written['street'] ?? ''), 'Ängavångsgatan 79'), 'produkttiteln byts mot gatan');
na_ok((string) ($written['postal_code'] ?? '') === '21219', 'postnumret fylls');
na_ok((string) ($written['city'] ?? '') === 'Malmö', 'orten fylls');
$afterCounts = jeab_contacts_sole_counts($store);
$afterLinks = jeab_contacts_sole_link_map($store);
na_ok($beforeCounts == $afterCounts, 'sole-räknarna är oförändrade');
na_ok(jeab_name_address_maps_same($beforeLinks, $afterLinks), 'contact_id på order, bokning och påminnelse består');
na_ok((string) ($store->getReminder((int) $backfillReminder['id'])['contact_id'] ?? '') === $backfillId, 'påminnelsen är kvar på samma kontakt');
na_ok((string) (jeab_purchase_get((int) $orderReminder['id'])['contact_id'] ?? '') === $keptId, 'ordern är kvar på samma kontakt');
$log = (string) (is_file($tmp . '/app-errors.jsonl') ? file_get_contents($tmp . '/app-errors.jsonl') : '');
na_ok(substr_count($log, 'name_address_backfill batch') === 1, 'en körning skriver en rad');
na_ok(str_contains($log, 'name_address_backfill batch ' . $batch . ' skrev 1 (name 1, street 1, postal_code 1, city 1)'), 'raden sammanfattar fälten');
na_ok(!str_contains($log, 'name_address_backfill kontakt'), 'inte en rad per fält');
na_ok(!str_contains($log, 'lirije.old@example.test'), 'loggen tar inte med mejladressen');
na_ok(!str_contains($log, 'Ängavångsgatan'), 'loggen tar inte med gatan');

$cardAfter = jeab_reminder_row($store->getReminder((int) $backfillReminder['id']), false);
na_ok(na_shut_name($cardAfter) === 'Lirije Hoxha', 'kortet visar namnet efter ifyllnaden');
na_ok(str_contains(na_shut_addr($cardAfter), 'Ängavångsgatan 79'), 'kortet visar gatan efter ifyllnaden');
na_ok(str_contains(na_note($cardAfter), 'Kund: Lirije Hoxha'), 'anteckningens rubrik följer kontakten');

$undoDry = jeab_kontakter_name_address_backfill_dispatch([
    'action' => 'kontakter.name_address_backfill',
    'undo' => 1,
    'batch_id' => $batch,
]);
na_ok(!empty($undoDry['ok']) && (int) ($undoDry['dry'] ?? 0) === 1 && empty($undoDry['written']), 'ångra är torrt som standard');
na_ok(jeab_kontakt_row_name(jeab_kontakt_get($backfillId) ?: []) === 'Lirije Hoxha', 'torr ångra skriver inte tillbaka');
$undo = jeab_kontakter_name_address_backfill_dispatch([
    'action' => 'kontakter.name_address_backfill',
    'undo' => 1,
    'dry' => 0,
    'batch_id' => $batch,
]);
na_ok(!empty($undo['ok']) && !empty($undo['written']) && (int) ($undo['sent'] ?? 1) === 0, 'ångra återställer utan att skicka');
na_ok(!empty($undo['counts_same']) && !empty($undo['links_same']), 'ångra lämnar antal och länkar');
$restored = jeab_kontakt_get($backfillId);
na_ok(is_array($restored), 'kontakten finns kvar efter ångra');
na_ok(jeab_kontakt_row_name($restored) === 'kund.a@example.invalid', 'namnet är tillbaka');
na_ok((string) ($restored['street'] ?? '') === 'Kund – Elbilsladdare', 'gatan är tillbaka');
na_ok((string) ($store->getReminder((int) $backfillReminder['id'])['contact_id'] ?? '') === $backfillId, 'ångra kopplar inte loss påminnelsen');
na_ok((string) (jeab_purchase_get((int) $orderReminder['id'])['contact_id'] ?? '') === $keptId, 'ångra kopplar inte loss ordern');
na_ok(jeab_contacts_sole_counts($store) == $afterCounts, 'ångra ändrar inte sole-räknarna');

$junk = jeab_name_address_contact_hint_from_lead([
    'from' => 'forms@wix-forms.com',
    'payload' => [
        'fields' => [
            ['label' => 'Namn', 'value' => 'Ada Ort'],
            ['label' => 'Adress', 'value' => 'Malmo'],
            ['label' => 'Ort', 'value' => 'Malmö.'],
        ],
    ],
], ['name' => '', 'email' => 'ada.ort@example.test', 'address' => ''], []);
na_ok($junk['street'] === '', 'gata utan husnummer hoppas över');
na_ok($junk['city'] === 'Malmö', 'avslutande punkt tas bort från orten');
$hud = jeab_name_address_normalize_place('Huddinge', '', '');
na_ok($hud['street'] === '', 'Huddinge utan nummer är ingen gata');
$between = jeab_name_address_normalize_place('Mellan Lund och Eslöv', '', '');
na_ok($between['street'] === '' && $between['city'] === '', 'text utan husnummer hoppas över');
$hjarup = jeab_name_address_normalize_place('Västerled 15 Hjärup', '', '');
na_ok($hjarup['street'] === 'Västerled 15' && $hjarup['city'] === 'Hjärup', 'känd ort efter numret blir ort');
$jakt = jeab_name_address_normalize_place('Jaktvagnsgatan 26 Malmö', '', 'Malmö (Se 4)');
na_ok($jakt['street'] === 'Jaktvagnsgatan 26' && $jakt['city'] === 'Malmö', 'parentes i orten tas bort och gatan behåller numret');
$see = jeab_name_address_clean_city('(Se 4)');
na_ok($see === '', 'ensam parentes är ingen ort');
$unit = jeab_name_address_normalize_place('Storgatan 12 lgh 2', '', '');
na_ok($unit['street'] === 'Storgatan 12 lgh 2' && $unit['city'] === '', 'lägenhet efter numret stannar på gatan');
na_ok(jeab_name_address_label_fragment('och ort:'), 'och ort: är en etikett');
na_ok(jeab_name_address_field_gap('street', 'och ort:', []) === 'placeholder', 'sparad och ort: är en platshållare');

$labelContact = $make('Etikett Person', 'etikett.person@example.test', 'och ort:', '', '');
$labelId = (string) $labelContact['id'];
jeab_kontakter_mutate(static function (array &$contacts) use ($labelId): void {
    $contacts[$labelId]['street'] = 'och ort:';
    $contacts[$labelId]['street_key'] = '';
    $contacts[$labelId]['city'] = 'Hjärup.';
    $contacts[$labelId]['postal_code'] = '';
});
$labelLead = jeab_inkorg_ingest([
    'message_id' => 'etikett-gata@example.test',
    'from' => 'etikett.person@example.test',
    'from_name' => 'Etikett Person',
    'to' => 'saljare.syd@malmo.example.test',
    'subject' => 'Adress',
    'body_text' => "Namn: Etikett Person\nAdress: Västerled 15 Hjärup\n",
    'source' => 'malmo-hs',
    'company' => 'malmo',
    'thread_key' => 'trad-etikett',
    'received_at' => '2026-10-23T09:00:00+02:00',
]);
na_ok(!empty($labelLead['ok']), 'etikettens lead tas in');
jeab_inkorg_thread_write('trad-etikett', static function (array $t) use ($labelId): array {
    $t['contact_id'] = $labelId;

    return $t;
});
$labelPlan = jeab_name_address_plan_contact($labelId, jeab_kontakt_get($labelId) ?: []);
na_ok(is_array($labelPlan), 'och ort: får en plan');
na_ok(($labelPlan['proposed']['street'] ?? '') === 'Västerled 15', 'planen byter etiketten mot gatan med nummer');
na_ok(($labelPlan['proposed']['city'] ?? '') === 'Hjärup', 'planen lägger orten från gatan');

$ready = $make('Estrella Anders', 'estrella.ready@example.test', 'Frostens väg 4', '21124', 'Malmö');
$readyId = (string) $ready['id'];
$readyRow = $store->insertReminder([
    'title' => 'estrella.ready@example.test – Elbilsladdare',
    'customer_name' => 'estrella.ready@example.test',
    'email' => 'estrella.ready@example.test',
    'address' => 'Kund – Elbilsladdare',
    'company' => 'malmo',
    'note' => "Kund: estrella.ready@example.test\n",
    'due_at' => '2026-10-24 15:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
na_ok(jeab_kontakt_stamp_reminder($store, (int) $readyRow['id'], $readyId), 'färdig kontakt är länkad');
na_ok(jeab_name_address_plan_contact($readyId, jeab_kontakt_get($readyId) ?: []) === null, 'namn och gata som redan är ifyllda saknas i planen');

$fromOrder = $make('Kund', 'peter.order@example.test');
$fromOrderId = (string) $fromOrder['id'];
jeab_kontakter_mutate(static function (array &$contacts) use ($fromOrderId): void {
    $contacts[$fromOrderId]['owners'][0]['name'] = 'peter.mathiesen@example.test';
    $contacts[$fromOrderId]['street'] = '';
    $contacts[$fromOrderId]['city'] = '';
    $contacts[$fromOrderId]['postal_code'] = '';
});
$orderOnly = $store->insertReminder([
    'title' => 'peter.mathiesen@example.test',
    'customer_name' => 'Peter Mathiesen',
    'email' => 'peter.order@example.test',
    'address' => 'Ängavångsgatan 79',
    'postal' => '21219',
    'ort' => 'Malmö',
    'company' => 'malmo',
    'note' => "Kund: Peter Mathiesen\n",
    'due_at' => '2026-10-24 16:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
na_ok(jeab_kontakt_stamp_reminder($store, (int) $orderOnly['id'], $fromOrderId), 'orderkällan är länkad');
$orderPlan = jeab_name_address_plan_contact($fromOrderId, jeab_kontakt_get($fromOrderId) ?: []);
na_ok(is_array($orderPlan), 'påminnelsen räcker som källa när tråden saknas');
na_ok(($orderPlan['proposed']['name'] ?? '') === 'Peter Mathiesen', 'påminnelsens namn fyller mejlnamnet');
na_ok(($orderPlan['proposed']['street'] ?? '') === 'Ängavångsgatan 79', 'påminnelsens gata har husnummer');
na_ok(($orderPlan['proposed']['city'] ?? '') === 'Malmö', 'påminnelsens ort följer med');

$noSource = $make('Kund', 'me.nosource@example.test');
$noSourceId = (string) $noSource['id'];
jeab_kontakter_mutate(static function (array &$contacts) use ($noSourceId): void {
    $contacts[$noSourceId]['owners'][0]['name'] = 'me@example.test';
    $contacts[$noSourceId]['street'] = 'Kund – Elbilsladdare';
    $contacts[$noSourceId]['street_key'] = '';
});
$noSourceRow = $store->insertReminder([
    'title' => 'me@example.test',
    'customer_name' => 'me@example.test',
    'email' => 'me.nosource@example.test',
    'address' => 'Kund – Elbilsladdare',
    'company' => 'malmo',
    'note' => "Kund: me@example.test\n",
    'due_at' => '2026-10-24 17:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
na_ok(jeab_kontakt_stamp_reminder($store, (int) $noSourceRow['id'], $noSourceId), 'källa utan namn är länkad');
na_ok(jeab_name_address_plan_contact($noSourceId, jeab_kontakt_get($noSourceId) ?: []) === null, 'titel och produkttitel ger ingen plan');

$boom = jeab_name_address_guard(static function (): array {
    throw new RuntimeException('index saknas');
});
na_ok((int) ($boom['status'] ?? 0) === 500 && ($boom['error'] ?? '') === 'name_address_backfill avbröts.', 'felet fångas');
$failLog = (string) (is_file($tmp . '/app-errors.jsonl') ? file_get_contents($tmp . '/app-errors.jsonl') : '');
na_ok(str_contains($failLog, 'name_address_backfill avbröts:'), 'felloggen säger att påfyllnaden avbröts');
na_ok(str_contains($failLog, 'index saknas'), 'felloggen tar med felet');

$page1 = jeab_kontakter_name_address_backfill(['dry' => 1, 'limit' => 1], $store);
na_ok((int) ($page1['scanned'] ?? 0) === 1 && (int) ($page1['done'] ?? 1) === 0, 'första sidan tar en kontakt');
$cursor = (string) ($page1['next_after_id'] ?? '');
na_ok($cursor !== '', 'första sidan lämnar en markör');
$page2 = jeab_kontakter_name_address_backfill(['dry' => 1, 'limit' => 1, 'after_id' => $cursor], $store);
na_ok((string) ($page2['next_after_id'] ?? '') !== $cursor, 'nästa sida går vidare');
na_ok((int) ($page2['limit'] ?? 0) === 1, 'limit följer med');

$chainA = $make('Kund', 'chain.a@example.test');
$chainB = $make('Kund', 'chain.b@example.test');
$chainAId = (string) $chainA['id'];
$chainBId = (string) $chainB['id'];
jeab_kontakter_mutate(static function (array &$contacts) use ($chainAId, $chainBId): void {
    $contacts[$chainAId]['owners'][0]['name'] = 'chain.a@example.test';
    $contacts[$chainBId]['owners'][0]['name'] = 'chain.b@example.test';
    $contacts[$chainAId]['street'] = '';
    $contacts[$chainBId]['street'] = '';
});
foreach ([[$chainAId, 'chain.a@example.test', 'Kedjegatan 1', 'Anna Kedja'], [$chainBId, 'chain.b@example.test', 'Kedjegatan 2', 'Bo Kedja']] as $spec) {
    $row = $store->insertReminder([
        'title' => $spec[1],
        'customer_name' => $spec[3],
        'email' => $spec[1],
        'address' => $spec[2],
        'postal' => '21220',
        'ort' => 'Malmö',
        'company' => 'malmo',
        'note' => "Kund: Kund\n",
        'due_at' => '2026-10-24 18:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    na_ok(jeab_kontakt_stamp_reminder($store, (int) $row['id'], $spec[0]), 'kedjan är länkad');
}
$chunk1 = jeab_kontakter_name_address_backfill_dispatch([
    'action' => 'kontakter.name_address_backfill',
    'dry' => 0,
    'limit' => 1,
    'contact_id' => $chainAId,
]);
na_ok(!empty($chunk1['written']), 'första skrivsidan skriver');
$sharedBatch = (string) ($chunk1['batch_id'] ?? '');
na_ok($sharedBatch !== '', 'första sidan får batch_id');
$chunk2 = jeab_kontakter_name_address_backfill_dispatch([
    'action' => 'kontakter.name_address_backfill',
    'dry' => 0,
    'limit' => 1,
    'after_id' => $chainAId,
    'contact_id' => $chainBId,
    'batch_id' => $sharedBatch,
]);
na_ok((string) ($chunk2['batch_id'] ?? '') === $sharedBatch, 'nästa sida använder samma batch_id');
$undoRows = 0;
foreach (jeab_contacts_sole_undo_all() as $undoRow) {
    if ((string) ($undoRow['batch_id'] ?? '') === $sharedBatch && (string) ($undoRow['kind'] ?? '') === 'name_address') {
        $undoRows++;
    }
}
na_ok($undoRows >= 2, 'båda sidorna ligger i samma ångra-batch');
$chainUndo = jeab_kontakter_name_address_backfill_dispatch([
    'action' => 'kontakter.name_address_backfill',
    'undo' => 1,
    'dry' => 0,
    'batch_id' => $sharedBatch,
]);
na_ok(!empty($chainUndo['written']), 'en ångra tar båda sidorna');
na_ok(jeab_kontakt_row_name(jeab_kontakt_get($chainAId) ?: []) === 'chain.a@example.test', 'första sidan är ångrad');
na_ok(jeab_kontakt_row_name(jeab_kontakt_get($chainBId) ?: []) === 'chain.b@example.test', 'andra sidan är ångrad');

$hsCases = [
    ['Peter Mathiesen', 'peter.card@example.test', 'Petergatan 11'],
    ['Ekrem Colakoff', 'ekrem.colakoff@example.test', 'Ekremgatan 12'],
    ['Jörgen Persson', 'jorgen.persson@example.test', 'Jorgengatan 13'],
    ['David Larsson', 'david.larsson@example.test', 'Davidgatan 14'],
    ['Martin Widén', 'martin.widen@example.test', 'Martingatan 15'],
];
foreach ($hsCases as $i => $spec) {
    [$person, $email, $street] = $spec;
    $row = $make($person, $email);
    $id = (string) $row['id'];
    jeab_kontakter_mutate(static function (array &$contacts) use ($id): void {
        $contacts[$id]['street'] = '';
        $contacts[$id]['street_key'] = '';
        $contacts[$id]['postal_code'] = '';
        $contacts[$id]['city'] = '';
    });
    $tid = 'trad-hs-card-' . $i;
    $ingested = jeab_inkorg_ingest([
        'message_id' => 'hs-card-' . $i . '@example.test',
        'from' => $email,
        'from_name' => $person,
        'to' => 'saljare.syd@malmo.example.test',
        'subject' => 'Hej',
        'body_text' => 'Hej, jag vill ha en offert.',
        'source' => 'helpscout_import',
        'company' => 'malmo',
        'thread_key' => $tid,
        'received_at' => '2026-10-22T08:00:00+02:00',
    ]);
    na_ok(!empty($ingested['ok']), $person . ' har en Help Scout-tråd');
    jeab_inkorg_thread_write($tid, static function (array $t) use ($person, $email, $street): array {
        $t['source'] = 'helpscout_import';
        $t['customer'] = [
            'email' => $email,
            'firstName' => $person,
            'address' => ['lines' => [$street], 'city' => 'Malmö', 'postalCode' => '211 20'],
        ];
        if (isset($t['messages'][0]) && is_array($t['messages'][0])) {
            $t['messages'][0]['source'] = 'helpscout_import';
            $t['messages'][0]['hs_name_display'] = $person;
            $t['messages'][0]['customer'] = $t['customer'];
        }

        return $t;
    });
    $plan = jeab_name_address_plan_contact($id, jeab_kontakt_get($id) ?: []);
    na_ok(is_array($plan), $person . ' får gata från Help Scout-kunden på tråden');
    na_ok(($plan['proposed']['street'] ?? '') === $street, $person . ' får husnumret');
    na_ok(($plan['source'] ?? '') === 'helpscout', $person . ' källan är helpscout');
}

$estrella = $make('Kund', 'estrellaexempel@gmail.com');
$estrellaId = (string) $estrella['id'];
jeab_kontakter_mutate(static function (array &$contacts) use ($estrellaId): void {
    $contacts[$estrellaId]['owners'][0]['name'] = 'estrellaexempel@gmail.com';
    $contacts[$estrellaId]['street'] = '';
    $contacts[$estrellaId]['city'] = '';
    $contacts[$estrellaId]['postal_code'] = '';
});
$estrellaRow = $store->insertReminder([
    'title' => 'estrellaexempel@gmail.com',
    'customer_name' => 'Kund',
    'email' => 'estrellaexempel@gmail.com',
    'address' => '',
    'company' => 'malmo',
    'note' => "Kund: Kund\n",
    'due_at' => '2026-10-24 19:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
na_ok(jeab_kontakt_stamp_reminder($store, (int) $estrellaRow['id'], $estrellaId), 'Estrellas kort är länkat');
$estrellaTid = 'trad-estrella-anders';
na_ok(!empty(jeab_inkorg_ingest([
    'message_id' => 'estrella-anders@example.test',
    'from' => 'estrellaexempel@gmail.com',
    'from_name' => 'estrellaexempel@gmail.com',
    'to' => 'saljare.syd@malmo.example.test',
    'subject' => 'Laddbox',
    'body_text' => 'Hej',
    'source' => 'malmo-hs',
    'company' => 'malmo',
    'thread_key' => $estrellaTid,
    'received_at' => '2026-10-22T09:30:00+02:00',
])['ok']), 'Estrellas tråd tas in');
jeab_inkorg_thread_write($estrellaTid, static function (array $t): array {
    $t['customer_email'] = 'estrellaexempel@gmail.com';
    $t['primaryCustomer'] = [
        'email' => 'estrellaexempel@gmail.com',
        'firstName' => 'Estrella',
        'lastName' => 'Anders',
        'address' => ['lines' => ['Andersgatan 7'], 'city' => 'Malmö', 'postalCode' => '21219'],
    ];

    return $t;
});
$estrellaPlan = jeab_name_address_plan_contact($estrellaId, jeab_kontakt_get($estrellaId) ?: []);
na_ok(is_array($estrellaPlan), 'Estrellas kort med Kund: Kund fylls från tråden');
na_ok(($estrellaPlan['proposed']['name'] ?? '') === 'Estrella Anders', 'Estrellas namn kommer från Help Scout');
na_ok(($estrellaPlan['proposed']['street'] ?? '') === 'Andersgatan 7', 'Estrellas gata kommer från Help Scout');

$frost = $make('Kund', 'me@frostensson.se');
$frostId = (string) $frost['id'];
jeab_kontakter_mutate(static function (array &$contacts) use ($frostId): void {
    $contacts[$frostId]['owners'][0]['name'] = 'me@frostensson.se';
    $contacts[$frostId]['street'] = '';
});
$frostRow = $store->insertReminder([
    'title' => 'me@frostensson.se',
    'customer_name' => 'Kund',
    'email' => 'me@frostensson.se',
    'address' => '',
    'company' => 'malmo',
    'note' => "Kund: Kund\n",
    'due_at' => '2026-10-24 19:30:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
na_ok(jeab_kontakt_stamp_reminder($store, (int) $frostRow['id'], $frostId), 'Frostens kort är länkat');
na_ok(!empty(jeab_inkorg_ingest([
    'message_id' => 'frostensson@example.test',
    'from' => 'me@frostensson.se',
    'from_name' => 'Me Frostensson',
    'to' => 'saljare.syd@malmo.example.test',
    'subject' => 'Batteri',
    'body_text' => "Namn: Me Frostensson\nAdress: Frostens väg 2\nPostnummer: 21124\nOrt: Malmö\n",
    'source' => 'malmo-hs',
    'company' => 'malmo',
    'thread_key' => 'trad-frostensson',
    'received_at' => '2026-10-22T10:00:00+02:00',
])['ok']), 'Frostens tråd tas in');
$frostPlan = jeab_name_address_plan_contact($frostId, jeab_kontakt_get($frostId) ?: []);
na_ok(is_array($frostPlan), 'me@frostensson.se fylls via mejlet på tråden');
na_ok(($frostPlan['proposed']['name'] ?? '') === 'Me Frostensson', 'Frostens namn läses');
na_ok(($frostPlan['proposed']['street'] ?? '') === 'Frostens väg 2', 'Frostens gata läses');

$lirijeOrder = $make('Kund', 'lirije.live@example.test');
$lirijeOrderId = (string) $lirijeOrder['id'];
jeab_kontakter_mutate(static function (array &$contacts) use ($lirijeOrderId): void {
    $contacts[$lirijeOrderId]['owners'][0]['name'] = 'kund.a@example.invalid';
    $contacts[$lirijeOrderId]['street'] = '';
    $contacts[$lirijeOrderId]['city'] = '';
    $contacts[$lirijeOrderId]['postal_code'] = '';
});
$lirijeReminder = $store->insertReminder([
    'title' => 'kund.a@example.invalid',
    'customer_name' => 'kund.a@example.invalid',
    'email' => 'lirije.live@example.test',
    'address' => 'Ängavångsgatan 79',
    'postal' => '21219',
    'ort' => 'Malmö',
    'company' => 'malmo',
    'note' => "Kund: Kund\n",
    'due_at' => '2026-10-24 20:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
na_ok(jeab_kontakt_stamp_reminder($store, (int) $lirijeReminder['id'], $lirijeOrderId), 'Lirijes orderpåminnelse är länkad');
na_ok(!empty(jeab_inkorg_ingest([
    'message_id' => 'lirije-live-name@example.test',
    'from' => 'lirije.live@example.test',
    'from_name' => 'Lirije Hoxha',
    'to' => 'saljare.syd@malmo.example.test',
    'subject' => 'Order',
    'body_text' => 'Hej',
    'source' => 'malmo-hs',
    'company' => 'malmo',
    'thread_key' => 'trad-lirije-live',
    'received_at' => '2026-10-22T12:00:00+02:00',
])['ok']), 'Lirijes tråd tas in');
$lirijePlan = jeab_name_address_plan_contact($lirijeOrderId, jeab_kontakt_get($lirijeOrderId) ?: []);
na_ok(is_array($lirijePlan), 'ordern Ängavångsgatan 79 är en källa');
na_ok(($lirijePlan['proposed']['name'] ?? '') === 'Lirije Hoxha', 'kund.a@example.invalid byts mot namnet på tråden');
na_ok(($lirijePlan['proposed']['street'] ?? '') === 'Ängavångsgatan 79', 'orderns gata följer med');

$khalid = $make('Khalid Imran', 'khalid.imran@example.test', 'Malmö', '', 'Malmö');
$khalidId = (string) $khalid['id'];
jeab_kontakter_mutate(static function (array &$contacts) use ($khalidId): void {
    $contacts[$khalidId]['street'] = 'Malmö';
    $contacts[$khalidId]['street_key'] = '';
    $contacts[$khalidId]['city'] = 'Malmö';
    $contacts[$khalidId]['postal_code'] = '';
});
$khalidRow = $store->insertReminder([
    'title' => 'Khalid Imran',
    'customer_name' => 'Khalid Imran',
    'email' => 'khalid.imran@example.test',
    'address' => 'Malmö, Malmö',
    'company' => 'malmo',
    'due_at' => '2026-10-24 21:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
na_ok(jeab_kontakt_stamp_reminder($store, (int) $khalidRow['id'], $khalidId), 'Khalids kort är länkat');
$khalidShown = jeab_reminder_shown_customer($store->getReminder((int) $khalidRow['id']));
na_ok(($khalidShown['display'] ?? '') === 'Malmö', 'Malmö, Malmö visas som orten');
na_ok(($khalidShown['street'] ?? '') === '', 'orten står inte som gata');
na_ok(!empty(jeab_inkorg_ingest([
    'message_id' => 'khalid-imran@example.test',
    'from' => 'khalid.imran@example.test',
    'from_name' => 'Khalid Imran',
    'to' => 'saljare.syd@malmo.example.test',
    'subject' => 'Adress',
    'body_text' => 'Hej',
    'source' => 'malmo-hs',
    'company' => 'malmo',
    'thread_key' => 'trad-khalid',
    'received_at' => '2026-10-22T11:00:00+02:00',
])['ok']), 'Khalids tråd tas in');
jeab_inkorg_thread_write('trad-khalid', static function (array $t): array {
    $t['customer_email'] = 'khalid.imran@example.test';
    $t['customer'] = [
        'email' => 'khalid.imran@example.test',
        'firstName' => 'Khalid',
        'lastName' => 'Imran',
        'address' => ['lines' => ['Imrangatan 4'], 'city' => 'Malmö', 'postalCode' => '21432'],
    ];

    return $t;
});
$khalidPlan = jeab_name_address_plan_contact($khalidId, jeab_kontakt_get($khalidId) ?: []);
na_ok(is_array($khalidPlan), 'Khalid får en plan när postnumret saknas');
na_ok(($khalidPlan['proposed']['street'] ?? '') === 'Imrangatan 4', 'sifferlös gata byts mot Help Scout');
na_ok(($khalidPlan['proposed']['postal_code'] ?? '') === '21432', 'Khalids postnummer fylls');
na_ok(($khalidPlan['proposed']['city'] ?? '') === 'Malmö', 'Khalids ort är kvar');
$khalidExplain = jeab_kontakter_name_address_explain(['contact_id' => $khalidId]);
$khalidStreets = [];
foreach ((array) ($khalidExplain['sources'] ?? []) as $sourceRow) {
    if (is_array($sourceRow)) {
        $khalidStreets[] = (string) ($sourceRow['street'] ?? '');
    }
}
na_ok(($khalidExplain['stored']['street_kind'] ?? '') === 'placeholder', 'Khalids gata utan siffra är en platshållare');
na_ok((string) (($khalidExplain['plan']['street'] ?? '')) === 'Imrangatan 4', 'förklaringen tar Help Scout-gatan');
na_ok(in_array('Imrangatan 4', $khalidStreets, true), 'förklaringen visar Help Scout-gatan');
na_ok(!preg_match('/\d{6}[-+]?\d{4}/', (string) preg_replace('/\bkt_[0-9a-f]+\b/', 'kt_id', json_encode($khalidExplain, JSON_UNESCAPED_UNICODE) ?: '')), 'förklaringen har inget personnummer');

$bulkSeed = jeab_kontakt_get($keptId);
na_ok(is_array($bulkSeed), 'bulken har en mall');
jeab_kontakter_mutate(static function (array &$contacts) use ($bulkSeed): void {
    for ($i = 0; $i < 1000; $i++) {
        $id = sprintf('na-bulk-%04d', $i);
        $row = $bulkSeed;
        $row['id'] = $id;
        $row['street'] = '';
        $row['street_key'] = '';
        $row['postal_code'] = '';
        $row['city'] = '';
        $row['owners'][0]['name'] = 'Bulk Person ' . $i;
        $row['owners'][0]['email'] = 'bulk' . $i . '@example.test';
        $row['owners'][0]['emails'] = [['value' => 'bulk' . $i . '@example.test', 'kind' => 'private']];
        $contacts[$id] = $row;
    }
});
$started = microtime(true);
$cursor = '';
$pages = 0;
$seen = 0;
$fast = true;
do {
    $batchStarted = microtime(true);
    $page = jeab_kontakter_name_address_backfill([
        'dry' => 1,
        'limit' => 50,
        'after_id' => $cursor,
    ], $store);
    if (microtime(true) - $batchStarted > 1) {
        $fast = false;
    }
    $pages++;
    $seen += (int) ($page['scanned'] ?? 0);
    $next = (string) ($page['next_after_id'] ?? '');
    if ($next === $cursor && (int) ($page['done'] ?? 0) !== 1) {
        break;
    }
    $cursor = $next;
} while ((int) ($page['done'] ?? 0) !== 1 && $pages < 80);
$elapsed = microtime(true) - $started;
na_ok($seen >= 1000, 'tusen kontakter gås igenom');
na_ok($pages >= 20, 'tusen kontakter delas i sidor');
na_ok($fast, 'varje sida tar under en sekund');
na_ok($elapsed < 8, 'hela genomgången är snabb');

echo "kontakter name address: ok\n";
