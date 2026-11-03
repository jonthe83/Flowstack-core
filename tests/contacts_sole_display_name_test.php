<?php

declare(strict_types=1);

/**
 * Visningsnamn när kontakten är enda källa.
 * Kontaktpersonens riktiga namn, sedan sparat namn, aldrig «Kund»
 * eller en e-postlokaldel när ett riktigt namn finns. Saknas namn visas e-posten.
 * Påhittade rader. Inga personnummer.
 */

$tmp = sys_get_temp_dir() . '/jeab-sole-display-name-' . bin2hex(random_bytes(4));
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
require_once dirname(__DIR__) . '/src/layout.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_GET = ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'all'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';

function dn_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function dn_shut_name(string $html): string
{
    if (preg_match('/class="item-shut-name">([^<]*)</', $html, $m) !== 1) {
        return '';
    }

    return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
}

$store = jeab_store();
dn_ok($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();
jeab_ensure_contacts_tables($pdo, 'sqlite');
jeab_ensure_contact_id_columns($pdo, 'sqlite');
dn_ok(jeab_contacts_sole(), 'CONTACTS_SOLE är på');

$now = jeab_now();

$make = static function (string $name, string $email, string $phone): array {
    $row = jeab_kontakt_find_or_create([
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'address' => 'Visningsgatan 1, 111 22 Teststad',
        'city' => 'Teststad',
        'postal_code' => '11122',
    ], false);
    dn_ok(is_array($row) && trim((string) ($row['id'] ?? '')) !== '', 'kontakt ' . $email);

    return $row;
};

$setOwners = static function (string $id, array $owners): void {
    jeab_kontakter_mutate(static function (array &$contacts) use ($id, $owners): void {
        if (!isset($contacts[$id]) || !is_array($contacts[$id])) {
            return;
        }
        $contacts[$id]['owners'] = $owners;
    });
    if (function_exists('jeab_contacts_sole_cache_forget')) {
        jeab_contacts_sole_cache_forget($id);
    }
    if (function_exists('jeab_reminder_contacts_bump')) {
        jeab_reminder_contacts_bump();
    }
};

$remind = static function (JeabStore $store, string $contactId, string $customer, string $email, string $company, string $thread) use ($now): array {
    $row = $store->insertReminder([
        'title' => 'Kort',
        'customer_name' => $customer,
        'email' => $email,
        'phone' => '0701000300',
        'address' => 'Kortgatan 2, Teststad',
        'ort' => 'Teststad',
        'postal' => '11122',
        'company' => $company,
        'thread_id' => $thread,
        'due_at' => '2026-10-24 09:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    dn_ok(jeab_kontakt_stamp_reminder($store, (int) $row['id'], $contactId), 'länk ' . $customer);
    $fresh = $store->getReminder((int) $row['id']);
    dn_ok(is_array($fresh), 'rad ' . $customer);

    return $fresh;
};

$nora = $make('Nora Nilsson', 'nora.nilsson@example.test', '0701000301');
$noraId = (string) $nora['id'];
$noraRow = $remind($store, $noraId, 'Kund', 'nora.nilsson@example.test', 'jarfalla', 'trad-nora');
$noraShown = jeab_reminder_shown_customer($noraRow);
dn_ok($noraShown['name'] === 'Nora Nilsson', 'kontaktpersonens namn vinner över platshållaren Kund');
dn_ok(dn_shut_name(jeab_reminder_row($noraRow, false)) === 'Nora Nilsson', 'Järfälla-kortet visar Nora Nilsson');

$majaContact = $make('Kund', 'maja.malmo@example.test', '0701000302');
$majaId = (string) $majaContact['id'];
$setOwners($majaId, [
    ['name' => 'Kund', 'email' => 'maja.malmo@example.test', 'phone' => '0701000302', 'contact' => true],
    ['name' => 'Maja Malmö', 'email' => '', 'phone' => '', 'contact' => false],
]);
$majaRow = $remind($store, $majaId, 'Kund', 'maja.malmo@example.test', 'malmo', 'trad-maja');
$majaShown = jeab_reminder_shown_customer($majaRow);
dn_ok($majaShown['name'] === 'Maja Malmö', 'annan ägares fullständiga namn vinner över Kund');
dn_ok(dn_shut_name(jeab_reminder_row($majaRow, false)) === 'Maja Malmö', 'Malmö-kortet visar Maja Malmö');

$estrella = $make('Estrellaanders', 'estrellaanders@example.test', '0701000303');
$estrellaId = (string) $estrella['id'];
$setOwners($estrellaId, [
    ['name' => 'Estrellaanders', 'email' => 'estrellaanders@example.test', 'phone' => '', 'contact' => true],
]);
$estrellaRow = $remind($store, $estrellaId, 'Estrella Anders', 'estrellaanders@example.test', 'malmo', 'trad-estrella');
dn_ok(jeab_reminder_shown_customer($estrellaRow)['name'] === 'Estrella Anders', 'sparat namn vinner över e-postens lokaldel');
dn_ok(dn_shut_name(jeab_reminder_row($estrellaRow, false)) === 'Estrella Anders', 'Malmö-kortet visar Estrella Anders');

$leif = $make('Lerehn', 'lerehn@example.test', '0701000304');
$leifId = (string) $leif['id'];
$setOwners($leifId, [
    ['name' => 'Lerehn', 'email' => 'lerehn@example.test', 'phone' => '', 'contact' => true],
]);
$leifRow = $remind($store, $leifId, 'Lerehn', 'lerehn@example.test', 'malmo', 'trad-lerehn');
$leifShown = jeab_reminder_shown_customer($leifRow);
dn_ok($leifShown['name'] === 'Lerehn', 'ensamt förnamn behålls även när det liknar e-postens lokaldel');
dn_ok(dn_shut_name(jeab_reminder_row($leifRow, false)) === 'Lerehn', 'kortet behåller förnamnet Lerehn');

$onlyKund = $make('Kund', 'bara.kund@example.test', '0701000305');
$onlyId = (string) $onlyKund['id'];
$setOwners($onlyId, [
    ['name' => 'Kund', 'email' => 'bara.kund@example.test', 'phone' => '', 'contact' => true],
]);
$onlyRow = $remind($store, $onlyId, 'Kund', 'bara.kund@example.test', 'jarfalla', 'trad-bara');
dn_ok(jeab_reminder_shown_customer($onlyRow)['name'] === 'bara.kund@example.test', 'ensam platshållare Kund blir e-posten');

$empty = $make('Tom Visning', 'tom.visning@example.test', '0701000306');
$emptyId = (string) $empty['id'];
$setOwners($emptyId, [
    ['name' => '', 'email' => '', 'phone' => '', 'contact' => true],
]);
$gap = [
    'reminder_id' => 88001,
    'contact_id' => $emptyId,
    'name' => 'Kopia Lucka',
    'huvudsokande' => 'Kopia Lucka',
    'customer_name' => 'Kopia Lucka',
    'email' => 'kopia.lucka@example.test',
];
dn_ok(jeab_ordrar_name($gap) === 'Kopia Lucka', 'tom kontakt faller tillbaka till det sparade ordernamnet');

$otto = $make('Kund', 'otto.order@example.test', '0701000307');
$ottoId = (string) $otto['id'];
$setOwners($ottoId, [
    ['name' => 'Kund', 'email' => 'otto.order@example.test', 'phone' => '', 'contact' => true],
]);
$order = [
    'reminder_id' => 88002,
    'contact_id' => $ottoId,
    'huvudsokande' => 'Otto Order',
    'name' => 'Otto Order',
    'customer_name' => 'Kund',
    'email' => 'otto.order@example.test',
];
dn_ok(jeab_ordrar_name($order) === 'Otto Order', 'orderns sparade namn vinner över Kund');

$elsa = $make('Kund', 'elsa.jarfalla@example.test', '0701000308');
$elsaId = (string) $elsa['id'];
$setOwners($elsaId, [
    ['name' => 'Kund', 'email' => 'elsa.jarfalla@example.test', 'phone' => '', 'contact' => true],
]);
$elsaRow = $remind($store, $elsaId, 'Elsa Järfälla', 'elsa.jarfalla@example.test', 'jarfalla', 'trad-elsa');
$elsaThread = [
    'id' => 'trad-elsa',
    'contact_id' => $elsaId,
    'customer_name' => 'Kund',
    'customer_email' => 'elsa.jarfalla@example.test',
    'brand' => 'jarfalla',
];
$elsaPanel = jeab_inkorg_panel_contact($elsaThread);
dn_ok(is_array($elsaPanel) && (string) ($elsaPanel['name'] ?? '') === 'Elsa Järfälla', 'Järfälla-panelen visar det sparade namnet');
dn_ok(jeab_contacts_sole_finish_display_name((string) $elsaPanel['name'], ['elsa.jarfalla@example.test']) === 'Elsa Järfälla', 'rubriken behåller Elsa Järfälla');

$malmoThread = [
    'id' => 'trad-estrella',
    'contact_id' => $estrellaId,
    'customer_name' => 'Estrellaanders',
    'customer_email' => 'estrellaanders@example.test',
    'brand' => 'malmo',
];
$malmoPanel = jeab_inkorg_panel_contact($malmoThread);
dn_ok(is_array($malmoPanel) && (string) ($malmoPanel['name'] ?? '') === 'Estrella Anders', 'Malmö-panelen visar Estrella Anders');

$leifThread = [
    'id' => 'trad-lerehn',
    'contact_id' => $leifId,
    'customer_name' => 'Lerehn',
    'customer_email' => 'lerehn@example.test',
    'brand' => 'malmo',
];
$leifPanel = jeab_inkorg_panel_contact($leifThread);
dn_ok(is_array($leifPanel) && (string) ($leifPanel['name'] ?? '') === 'lerehn@example.test', 'Malmö-panelen utan riktigt namn visar e-posten');

$party = jeab_inkorg_customer([
    'id' => 'trad-party',
    'customer_email' => 'party.kund@example.test',
    'customer_name' => 'Kund',
    'customer_source' => 'hook',
    'messages' => [[
        'from' => 'party.kund@example.test',
        'from_name' => 'Kund',
        'body' => 'Hej',
        'received_at' => $now,
    ]],
], []);
dn_ok(($party['name'] ?? 'x') === '', 'inkorgens kundnamn lämnar platshållaren Kund');

$kept = $make('Disa Adress', 'disa.adress@example.test', '0701000309');
$keptRow = $remind($store, (string) $kept['id'], 'Kopia Panel', 'kopia.panel@example.test', 'jarfalla', 'trad-disa');
dn_ok(jeab_reminder_shown_customer($keptRow)['name'] === 'Disa Adress', 'ett riktigt kontaktnamn vinner fortfarande över kopian');

$blob = json_encode([
    $noraShown,
    $majaShown,
    $leifShown,
    $elsaPanel,
    $malmoPanel,
], JSON_UNESCAPED_UNICODE);
dn_ok(is_string($blob) && !str_contains($blob, 'personnummer') && !preg_match('/\bpnr\b/i', $blob), 'svaret innehåller inga personnummer');

echo "contacts sole display name ok\n";
