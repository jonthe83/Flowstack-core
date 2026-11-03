<?php

declare(strict_types=1);

/**
 * Help Scout-import och kontakter, omgång 4. Påhittade namn och adresser.
 */

$tmp = sys_get_temp_dir() . '/jeab-hs4-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'people' => [
        ['id' => 'AliasExempel', 'email' => 'alias.exempel@example.com', 'name' => 'Alias Exempel', 'company' => 'jarfalla', 'phone' => '0701000000'],
    ],
], true) . ";\n");
file_put_contents($tmp . '/helpscout.json', json_encode([
    'client_id' => 'hs-test-client',
    'client_secret' => 'hs-test-secret',
    'mailbox_id' => 111,
    'mailbox_id_malmo' => 222,
    'import_supplier_domains' => ['levexempel.se'],
    'kontakter_exclude_domains' => ['uteexempel.se'],
    'import_supplier_domain_min' => 6,
], JSON_UNESCAPED_UNICODE));
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/layout.php';
jeab_ordrar_external_live(false);

function r4(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function r4_owner(array $row): string
{
    $owners = is_array($row['owners'] ?? null) ? $row['owners'] : [];
    $first = is_array($owners[0] ?? null) ? $owners[0] : [];
    return trim((string) ($first['name'] ?? ''));
}

function r4_by_email(string $email): ?array
{
    $email = jeab_kontakt_email($email);
    foreach (jeab_kontakter_load() as $row) {
        if (is_array($row) && jeab_kontakt_row_email($row) === $email) {
            return $row;
        }
    }
    return null;
}

function r4_named(string $name): array
{
    $want = jeab_kontakt_fold_text($name);
    $out = [];
    foreach (jeab_kontakter_load() as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (jeab_kontakt_fold_text(r4_owner($row)) === $want) {
            $out[] = $row;
        }
    }
    return $out;
}

/** @param array<string, mixed> $extra */
function r4_store(string $n, string $email, string $name, string $body, string $contactId = '', array $extra = []): string
{
    $tid = 'hsi-' . substr(hash('sha256', 'r4-' . $n), 0, 16);
    $msg = [
        'id' => substr(hash('sha256', 'r4m-' . $n), 0, 16),
        'from' => $email,
        'from_name' => $name,
        'to' => 'kontakt@example.test',
        'subject' => 'Fråga ' . $n,
        'body' => $body,
        'received_at' => '2024-05-02T09:00:00+00:00',
        'source' => 'helpscout_import',
        'company' => 'jarfalla',
        'hs_thread_type' => 'customer',
        'internal_note' => false,
        'attachments' => [],
    ];
    foreach ($extra as $k => $v) {
        $msg[$k] = $v;
    }
    $conv = [
        'id' => $tid,
        'company' => 'jarfalla',
        'status' => 'open',
        'import_source' => 'helpscout_import',
        'contact_id' => $contactId,
        'hs_ids' => ['conversation' => '900' . sprintf('%03d', abs(crc32($n)) % 1000), 'mailbox' => '111', 'customer' => ''],
        'messages' => [$msg],
        'meta' => [
            'status' => 'open',
            'tags' => [],
            'assignee' => '',
            'read' => [],
            'contact_id' => $contactId,
            'import_source' => 'helpscout_import',
        ],
    ];
    r4(jeab_hs_import_save_conv($conv) && jeab_hs_import_index_append(jeab_hs_import_index_row($conv)), 'ärende ' . $n . ' skrivs');
    return $tid;
}

$real = 'Hej, jag vill ha en laddbox till huset på Exempelgatan.';
$quoted = $real . "\n\n> Do not reply to this message\n";

r4(jeab_hs_import_choose_display('kund.komma@gmail.com', 'Exempelnamn, Alias') === 'Exempelnamn, Alias', 'trådens komma-namn vinner över adressen i profilen');
r4(jeab_hs_import_choose_display('Exempelnamn, Alias', '') === 'Exempelnamn, Alias', 'ett komma-namn i profilen behålls');
$comma = jeab_hs_import_contact_plan(['name' => 'Exempelnamn, Alias', 'email' => 'kund.komma@gmail.com', 'body' => $real, 'real_customer' => true]);
r4($comma['action'] === 'create' && $comma['name'] === 'Alias Exempelnamn' && ($comma['filter'] ?? '') === 'kept', 'komma-namn på gmail blir en kontakt');

$emailName = jeab_hs_import_contact_plan(['name' => 'kund.visning@gmail.com', 'email' => 'kund.visning@gmail.com', 'body' => $quoted, 'real_customer' => true]);
r4($emailName['action'] === 'create' && $emailName['name'] === 'Kund Visning', 'adressen som visningsnamn blir en kontakt');
r4(jeab_hs_import_message_is_real_customer(['from' => 'kund.visning@gmail.com', 'from_name' => 'kund.visning@gmail.com', 'body' => $quoted, 'hs_thread_type' => 'customer']) === true, 'citerad no-reply-fot fäller inte ett kundmejl');

$mailLabel = jeab_hs_import_contact_plan(['name' => 'Exempelmail', 'email' => 'exempelmail.test@example.invalid', 'body' => $real, 'real_customer' => true]);
r4($mailLabel['action'] === 'create' && $mailLabel['name'] === 'exempelmail.test@example.invalid' && !empty($mailLabel['hint']['name_missing']), 'local-del som slutar på mail blir namn saknas');

foreach ([
    'Qwrtzpkxvbn' => 'junk',
    'asdgasdg' => 'junk',
    'sol' => 'junk',
    'batteri' => 'junk',
    'laddare' => 'junk',
    'fjärde' => 'junk',
    'namn2' => 'junk',
] as $junk => $filter) {
    $plan = jeab_hs_import_contact_plan(['name' => $junk, 'email' => 'skrap.' . md5($junk) . '@gmail.com', 'body' => $real, 'real_customer' => true]);
    r4($plan['action'] === 'skip' && ($plan['filter'] ?? '') === $filter, $junk . ' tas bort som ' . $filter);
}
$products = jeab_hs_import_contact_plan(['name' => 'Kund Exempel', 'email' => 'products.alias@gmail.com', 'body' => $real, 'real_customer' => true]);
r4($products['action'] === 'skip' && ($products['filter'] ?? '') === 'test', 'products i local-delen är test');
$own = jeab_hs_import_contact_plan(['name' => 'Kund Exempel', 'email' => 'alias.sam@gmail.com', 'body' => $real, 'real_customer' => true]);
r4($own['action'] === 'skip' && ($own['filter'] ?? '') === 'test', 'sam i local-delen är test');
r4(jeab_hs_import_contact_plan(['name' => 'Kund Exempel', 'email' => 'kund.vanlig@gmail.com', 'body' => $real, 'real_customer' => true])['action'] === 'create', 'ett vanligt namn på gmail behålls');

$unknownPlace = jeab_kontakt_normalize_person_name('Anna Exempel / Exempelby');
r4($unknownPlace['name'] === 'Anna Exempel / Exempelby' && $unknownPlace['city'] === '', 'okänd ort efter snedstreck lämnar namnet');
$knownPlace = jeab_kontakt_normalize_person_name('Anna Exempel / Lund');
r4($knownPlace['name'] === 'Anna Exempel' && $knownPlace['city'] === 'Lund', 'Lund lämnar namnet');
$hbg = jeab_kontakt_normalize_person_name('Pelle Hbg');
r4($hbg['name'] === 'Pelle' && $hbg['city'] === 'Helsingborg', 'Hbg blir Helsingborg');
$fromCity = jeab_kontakt_normalize_person_name('Anna Exempel / Exempelby', 'Exempelby');
r4($fromCity['name'] === 'Anna Exempel' && $fromCity['city'] === 'Exempelby', 'orten i adressfältet får lämna namnet');

foreach ([
    'Anna Berg' => 'Anna Berg',
    'Per Lund' => 'Per Lund',
    'Lisa Mora' => 'Lisa Mora',
] as $rawName => $keptName) {
    $kept = jeab_kontakt_normalize_person_name($rawName);
    r4($kept['name'] === $keptName && $kept['city'] === '', $rawName . ' behåller efternamnet');
}
$flippedBerg = jeab_kontakt_normalize_person_name('Berg, Anna');
r4($flippedBerg['name'] === 'Anna Berg' && $flippedBerg['city'] === '', 'Berg, Anna vänds och efternamnet får ligga kvar');
$flippedKnown = jeab_kontakt_normalize_person_name('Berg, Anna', 'Berg');
r4($flippedKnown['name'] === 'Anna Berg' && $flippedKnown['city'] === '', 'efter vändning skalas inget av även när orten är Berg');
$slashBerg = jeab_kontakt_normalize_person_name('Anna Berg / Lund');
r4($slashBerg['name'] === 'Anna Berg' && $slashBerg['city'] === 'Lund', 'Anna Berg / Lund behåller efternamnet och tar Lund');
$secondComma = jeab_kontakt_normalize_person_name('Berg, Anna, Lund');
r4($secondComma['name'] === 'Anna Berg' && $secondComma['city'] === 'Lund', 'orten efter andra kommat lämnar namnet');
foreach (['Sthlm' => 'Stockholm', 'Gbg' => 'Göteborg', 'Mlm' => 'Malmö'] as $abbrev => $cityName) {
    $short = jeab_kontakt_normalize_person_name('Pelle ' . $abbrev);
    r4($short['name'] === 'Pelle' && $short['city'] === $cityName, $abbrev . ' blir ' . $cityName);
}
$eva = jeab_kontakt_normalize_person_name('Eva Sala', 'Sala');
r4($eva['name'] === 'Eva Sala' && $eva['city'] === '', 'Eva Sala med orten Sala blir inte bara Eva');
$twoLeft = jeab_kontakt_normalize_person_name('Anna Berg Sala', 'Sala');
r4($twoLeft['name'] === 'Anna Berg' && $twoLeft['city'] === 'Sala', 'adressorten lämnar namnet när två ord blir kvar');
$surnames = [
    'Berg', 'Lund', 'Holm', 'Ek', 'Mark', 'Sala', 'Mora', 'Nora', 'Strand', 'Dahl',
    'Kil', 'Flen', 'Lind', 'Lindberg', 'Lindgren', 'Lindqvist', 'Lindström', 'Bergman', 'Bergström', 'Eriksson',
    'Johansson', 'Andersson', 'Karlsson', 'Nilsson', 'Larsson', 'Gustafsson', 'Pettersson', 'Hansson', 'Jönsson', 'Persson',
    'Bengtsson', 'Magnusson', 'Olofsson', 'Axelsson', 'Fredriksson', 'Sandberg', 'Forsberg', 'Lindholm', 'Holmberg', 'Holmgren',
    'Ekström', 'Ström', 'Blom', 'Blomberg', 'Nyberg', 'Nyström', 'Wallin', 'Lundberg', 'Lundgren', 'Berglund',
];
r4(count($surnames) === 50, 'regressionen har femtio efternamn');
foreach ($surnames as $surname) {
    $full = 'Karin ' . $surname;
    $norm = jeab_kontakt_normalize_person_name($full);
    r4($norm['name'] === $full && $norm['city'] === '', $full . ' behålls');
}
$linkedEva = jeab_kontakt_find_or_create(['name' => 'Eva Sala', 'email' => 'eva.sala.exempel@gmail.com', 'city' => 'Sala'], false);
r4(is_array($linkedEva) && r4_owner($linkedEva) === 'Eva Sala' && ($linkedEva['city'] ?? '') === 'Sala', 'kopplingen behåller Eva Sala när postorten är Sala');
$linkedBerg = jeab_kontakt_find_or_create(['name' => 'Anna Berg', 'email' => 'anna.berg.exempel@gmail.com', 'city' => 'Berg'], false);
r4(is_array($linkedBerg) && r4_owner($linkedBerg) === 'Anna Berg' && ($linkedBerg['city'] ?? '') === 'Berg', 'kopplingen behåller Anna Berg');

$row = jeab_hs_import_message_row([
    'id' => '91',
    'type' => 'customer',
    'createdBy' => ['first' => 'Exempelnamn,', 'last' => 'Alias', 'email' => 'kund.trad@gmail.com'],
    'customer' => ['firstName' => 'kund.trad@gmail.com', 'lastName' => '', 'email' => 'kund.trad@gmail.com'],
    'body' => '<p>Hej fran kunden</p>',
    'createdAt' => '2024-05-02T09:00:00Z',
], [
    'id' => '4242',
    'subject' => 'Hej',
    'primaryCustomer' => ['id' => '7', 'first' => 'kund.trad@gmail.com', 'last' => '', 'email' => 'kund.trad@gmail.com'],
], 'jarfalla', 111);
r4(is_array($row) && ($row['from_name'] ?? '') === 'Exempelnamn, Alias' && ($row['hs_name_display'] ?? '') === 'Exempelnamn, Alias', 'trådens avsändare sparas före kundprofilen');

$companyPlan = jeab_hs_import_contact_plan(['name' => 'Exempel samfällighet', 'email' => 'styrelsen@exempelsam.se', 'body' => $real, 'real_customer' => true]);
r4($companyPlan['action'] === 'create' && ($companyPlan['hint']['kind'] ?? '') === 'company', 'samfällighet är ett bolag och inte en leverantör');
r4(jeab_hs_import_contact_plan(['name' => 'Exempel Bolaget AB', 'email' => 'ab@exempelbolaget.se', 'body' => $real, 'real_customer' => true])['action'] === 'skip', 'AB är fortfarande en leverantör');

$kept = jeab_kontakt_blank(['name' => 'Kund Riktig', 'email' => 'kund.riktig@gmail.com', 'street' => 'Exempelgatan 2', 'street_key' => jeab_kontakt_street_key('Exempelgatan 2')]);
$supplier = jeab_kontakt_blank(['name' => 'Leverantor Exempel', 'email' => 'order@levexempel.se']);
$excluded = jeab_kontakt_blank(['name' => 'Ute Exempel', 'email' => 'info@uteexempel.se']);
$newsletter = jeab_kontakt_blank(['name' => 'Brev Exempel', 'email' => 'info@newsletter.example']);
$brandJe = jeab_kontakt_blank(['name' => 'Demo Energi Järfälla', 'email' => 'formular.je@example.test']);
$brandMe = jeab_kontakt_blank(['name' => 'Demo Energi Malmö', 'email' => 'formular.me@example.test']);
$form = jeab_kontakt_blank(['name' => 'Formulär', 'email' => 'formular@example.test']);
$event = jeab_kontakt_blank(['name' => 'Möte Exempel', 'email' => 'mote@example.test']);
$staff = jeab_kontakt_blank(['name' => 'Alias Exempel', 'email' => 'alias.intern@example.test']);
$staffKept = jeab_kontakt_blank(['name' => 'Alias Exempel', 'email' => 'alias.kund@gmail.com']);
$locked = jeab_kontakt_blank(['name' => 'Leverantor Exempel', 'email' => 'pnr@levexempel.se']);
$locked['owners'][0]['personnummer'] = 'sekretess';
jeab_kontakter_mutate(function (array &$contacts) use ($kept, $supplier, $excluded, $newsletter, $brandJe, $brandMe, $form, $event, $staff, $staffKept, $locked): void {
    foreach ([$kept, $supplier, $excluded, $newsletter, $brandJe, $brandMe, $form, $event, $staff, $staffKept, $locked] as $row) {
        $contacts[(string) $row['id']] = $row;
    }
});
$store = jeab_store();
$reminder = jeab_create_reminder($store, 'Kund', jeab_today_start(), null, null, [
    'name' => 'Kund Riktig',
    'email' => 'kund.riktig@gmail.com',
    'phone' => '0701000091',
    'street' => 'Exempelgatan 2',
    'postal_code' => '11122',
    'city' => 'Lund',
]);
$store->saveReminder((int) $reminder['id'], ['contact_id' => (string) $kept['id']]);
$staffReminder = jeab_create_reminder($store, 'Kund', jeab_today_start(), null, null, [
    'name' => 'Alias Exempel',
    'email' => 'alias.kund@gmail.com',
    'phone' => '0701000092',
    'street' => 'Kundgatan 3',
    'postal_code' => '11122',
    'city' => 'Lund',
]);
$store->saveReminder((int) $staffReminder['id'], ['contact_id' => (string) $staffKept['id']]);

$prune = jeab_kontakter_prune([], $store);
r4(!empty($prune['dry']) && ($prune['removed'] ?? 1) === 0, 'prune är torr som standard');
$pruneIds = [];
foreach ((array) ($prune['contacts'] ?? []) as $candidate) {
    if (is_array($candidate)) {
        $pruneIds[] = (string) ($candidate['id'] ?? '');
    }
}
sort($pruneIds);
$expect = [
    (string) $supplier['id'],
    (string) $excluded['id'],
    (string) $newsletter['id'],
    (string) $brandJe['id'],
    (string) $brandMe['id'],
    (string) $form['id'],
    (string) $event['id'],
    (string) $staff['id'],
];
sort($expect);
r4($pruneIds === $expect, 'prune föreslår leverantör, nyhetsbrev, varumärke, formulär, händelse och personal');
r4(!in_array((string) $kept['id'], $pruneIds, true) && !in_array((string) $staffKept['id'], $pruneIds, true) && !in_array((string) $locked['id'], $pruneIds, true), 'riktig kund, kopplad personal och personnummer lämnas');

$domainA = jeab_kontakt_blank(['name' => 'Domän Exempel', 'email' => 'doman.a@exempelbolag.se']);
$domainB = jeab_kontakt_blank(['name' => 'Domän Exempel', 'email' => 'doman.b@exempelbolag.se']);
$sameA = jeab_kontakt_blank(['name' => 'Samma Exempel', 'email' => 'samma.exempel@gmail.com']);
$sameB = jeab_kontakt_blank(['name' => 'Samma Exempel', 'email' => 'samma.exempel@gmail.com']);
$coA = jeab_kontakt_blank(['name' => 'Eken samfällighet', 'email' => 'eken.a@gmail.com']);
$coB = jeab_kontakt_blank(['name' => 'Eken samfällighet', 'email' => 'eken.b@gmail.com']);
jeab_kontakter_mutate(function (array &$contacts) use ($domainA, $domainB, $sameA, $sameB, $coA, $coB): void {
    foreach ([$domainA, $domainB, $sameA, $sameB, $coA, $coB] as $row) {
        $contacts[(string) $row['id']] = $row;
    }
});

$commaTid = r4_store('komma', 'kund.komma@gmail.com', 'kund.komma@gmail.com', $real, '', [
    'hs_name_first' => 'Exempelnamn,',
    'hs_name_last' => 'Alias',
    'hs_name_display' => 'Exempelnamn, Alias',
]);
r4_store('visning', 'kund.visning@gmail.com', 'kund.visning@gmail.com', $quoted);
r4_store('maillabel', 'exempelmail.test@example.invalid', 'Exempelmail', $real);
r4_store('mos', 'mos.exempel@gmail.com', 'Qwrtzpkxvbn', $real);
r4_store('products', 'products.alias@gmail.com', 'Kund Exempel', $real);
r4_store('sol', 'sol.exempel@gmail.com', 'sol', $real);
r4_store('doman-a', 'doman.a@exempelbolag.se', 'Domän Exempel', $real, (string) $domainA['id']);
r4_store('doman-b', 'doman.b@exempelbolag.se', 'Domän Exempel', $real, (string) $domainB['id']);
r4_store('samma-a', 'samma.exempel@gmail.com', 'Samma Exempel', $real, (string) $sameA['id']);
r4_store('samma-b', 'samma.exempel@gmail.com', 'Samma Exempel', $real, (string) $sameB['id']);
r4_store('eken-a', 'eken.a@gmail.com', 'Eken samfällighet', $real, (string) $coA['id']);
r4_store('eken-b', 'eken.b@gmail.com', 'Eken samfällighet', $real, (string) $coB['id']);

$diag = jeab_hs_import_hook(['action' => 'helpscout.contact_diag', 'q' => $commaTid]);
r4(!empty($diag['ok']) && count((array) ($diag['cases'] ?? [])) === 1, 'contact_diag träffar hsi-id');
$diagCase = (array) (($diag['cases'][0] ?? []));
r4(($diagCase['display'] ?? '') === 'Exempelnamn, Alias' && ($diagCase['first'] ?? '') === 'Exempelnamn,' && ($diagCase['last'] ?? '') === 'Alias', 'diag visar råa namnfält');
r4(($diagCase['normalised'] ?? '') === 'Alias Exempelnamn' && ($diagCase['filter'] ?? '') === 'kept', 'diag visar normaliserat namn och kept');
r4(!array_key_exists('body', $diagCase) && !str_contains((string) json_encode($diag), 'laddbox'), 'diag tar inte med mejltext');
$byName = jeab_hs_import_contact_diag(['q' => 'exempelmail']);
r4(!empty($byName['ok']) && (($byName['cases'][0]['filter'] ?? '') === 'kept') && str_contains((string) ($byName['cases'][0]['reason'] ?? ''), 'namn saknas'), 'diag för local-delen som slutar på mail');

$dry = jeab_hs_import_reprocess([]);
r4(!empty($dry['dry']) && ($dry['contacts_recreated'] ?? 0) === 3, 'torrkörning räknar komma-namn, visningsadress och namn saknas');
r4(($dry['contacts_merged'] ?? 0) === 3 && ($dry['contacts_conflict'] ?? 0) === 0, 'torrkörning slår ihop domän, samma e-post och samfällighet');
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
r4(is_string($dryJson) && !str_contains($dryJson, '@') && !str_contains($dryJson, 'Exempelnamn'), 'torrkörningens svar är bara antal');

$wet = jeab_hs_import_reprocess(['dry' => 0]);
r4(empty($wet['dry']) && ($wet['contacts_recreated'] ?? 0) === 3 && ($wet['contacts_merged'] ?? 0) === 3, 'omkörning skapar och slår ihop');
$commaRow = r4_by_email('kund.komma@gmail.com');
r4(is_array($commaRow) && r4_owner($commaRow) === 'Alias Exempelnamn', 'komma-namnet från tråden blir en kontakt');
r4(r4_owner((array) r4_by_email('kund.visning@gmail.com')) === 'Kund Visning', 'adressen som namn blir Kund Visning');
$mailRow = r4_by_email('exempelmail.test@example.invalid');
r4(is_array($mailRow) && !empty($mailRow['name_missing']) && r4_owner($mailRow) === 'exempelmail.test@example.invalid', 'Exempelmail markeras namn saknas');
r4(r4_by_email('mos.exempel@gmail.com') === null && r4_by_email('products.alias@gmail.com') === null && r4_by_email('sol.exempel@gmail.com') === null, 'mos, products och sol kommer inte tillbaka');
r4(count(r4_named('Domän Exempel')) === 1, 'samma namn och samma domän slås ihop');
r4(count(r4_named('Samma Exempel')) === 1, 'samma namn och samma e-post slås ihop');
$companies = r4_named('Eken samfällighet');
r4(count($companies) === 1 && ($companies[0]['kind'] ?? '') === 'company', 'samfällighet blir en företagskontakt');

$again = jeab_hs_import_reprocess(['dry' => 0]);
r4(($again['contacts_recreated'] ?? 1) === 0 && ($again['contacts_merged'] ?? 1) === 0, 'andra omkörningen ändrar inte antalen');

$_GET = ['p' => 'kontakter'];
$html = (string) include dirname(__DIR__) . '/src/pages/kontakter.php';
r4(str_contains($html, 'Alias Exempelnamn') && str_contains($html, 'Kund Visning') && str_contains($html, 'Eken samfällighet'), 'kontaktlistan visar de påhittade namnen');
r4(str_contains($html, 'namn saknas') && str_contains($html, 'exempelmail.test@example.invalid'), 'listan visar namn saknas');
r4(!str_contains($html, 'Qwrtzpkxvbn') && !str_contains($html, 'products.alias@gmail.com'), 'skräp och testadress syns inte');

echo "OK  round 4\n";
