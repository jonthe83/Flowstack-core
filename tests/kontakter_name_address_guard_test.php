<?php

declare(strict_types=1);

/**
 * contact_ids, ort- och gatakoll, testkontakter, förklaring och fellogg.get.
 * Påhittade personer. Inga personnummer i svaret.
 */

$tmp = sys_get_temp_dir() . '/jeab-name-guard-' . bin2hex(random_bytes(4));
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
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';

function ng_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
ng_ok($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();
jeab_ensure_contacts_tables($pdo, 'sqlite');
jeab_ensure_contact_id_columns($pdo, 'sqlite');
$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-name-guard']));
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-name-guard';
$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
ng_ok(str_contains($hook, "'kontakter.name_address_explain'") && str_contains($hook, 'jeab_kontakter_name_address_explain_dispatch'), 'hook förklarar');
ng_ok(str_contains($hook, "'kontakter.find'") && str_contains($hook, 'jeab_kontakter_find_dispatch'), 'hook söker kontakter');
ng_ok(str_contains($hook, "=== 'fellogg.get'") && str_contains($hook, 'jeab_inkorg_hook_authorized'), 'fellogg.get kräver token');

ng_ok(jeab_name_address_city_kind('The Netherlands') === 'placeholder', 'land är ingen ort');
ng_ok(jeab_name_address_city_kind('1025') === 'placeholder', 'siffror är ingen ort');
ng_ok(jeab_name_address_city_kind('Hjl Kjhkj Hk') === 'placeholder', 'tangentbordsskräp är ingen ort');
ng_ok(jeab_name_address_city_kind('Gfhdw') === 'placeholder', 'Gfhdw är ingen ort');
ng_ok(jeab_name_address_city_kind('Test') === 'placeholder', 'Test är ingen ort');
ng_ok(jeab_name_address_city_kind('Teststad') === 'unknown', 'okänd ort som inte är skräp behålls');
ng_ok(jeab_name_address_city_kind('Malmö') === 'real', 'Malmö är en ort');
ng_ok(jeab_name_address_accept_city('The Netherlands', '21219') === 'Malmö', 'postnummer vinner över land');
ng_ok(jeab_name_address_accept_city('Limhamn', '21120') === 'Limhamn', 'stadsdel behålls när kommunen stämmer');
ng_ok(jeab_name_address_accept_city('Lund', '21219') === 'Lund', 'utskriven ort vinner över postnumrets intervall');
ng_ok(jeab_name_address_accept_city('Ödåkra', '25474') === 'Ödåkra', 'utskriven postort behålls');
ng_ok(jeab_name_address_accept_city('', '25474') === 'Helsingborg', 'tom ort fylls från postnumret');
ng_ok(jeab_name_address_accept_city('The Netherlands', '25474') === 'Helsingborg', 'avvisad ort fylls från postnumret');
$odakra = jeab_name_address_normalize_place('Ängavångsgatan 79, 254 74 Ödåkra', '', '');
ng_ok($odakra['street'] === 'Ängavångsgatan 79' && $odakra['postal'] === '25474' && $odakra['city'] === 'Ödåkra', 'adressen behåller Ödåkra');
ng_ok(jeab_name_address_name_real('Lirije', ['lirije@live.se']), 'ensamt förnamn är ett namn');
ng_ok(!jeab_name_address_name_real('lirije', ['lirije@live.se']), 'gemener är lokaldelen');
ng_ok(!jeab_name_address_name_real('LIRIJE', ['lirije@live.se']), 'versaler är lokaldelen');
ng_ok(!jeab_name_address_name_real('Estrellaexempel', ['estrellaexempel@gmail.com']), 'lång lokaldel är inget förnamn');
ng_ok(!jeab_name_address_given_name('Malmö') && !jeab_name_address_given_name('Test'), 'ort och Test är inga förnamn');
$fullName = jeab_name_address_merge_facts([
    ['at' => 20, 'name' => 'Lirije', 'street' => '', 'postal' => '', 'city' => '', 'source' => 'ny'],
    ['at' => 10, 'name' => 'Lirije Hoxha', 'street' => '', 'postal' => '', 'city' => '', 'source' => 'gammal'],
]);
ng_ok(($fullName['name'] ?? '') === 'Lirije Hoxha', 'fullständigt namn vinner över förnamnet');
ng_ok(jeab_name_address_clean_city('(Se 4)') === '', 'ensam parentes är ingen ort');

ng_ok(jeab_name_address_prepare_street('Evenemangsgatan 24 4tr') === 'Evenemangsgatan 24', 'våningsnotering tas bort');
ng_ok(jeab_name_address_prepare_street('Evenemangsgatan 24 4 tr') === 'Evenemangsgatan 24', 'våningsnotering med mellanslag tas bort');
ng_ok(!jeab_name_address_has_house_number('Skåne (SolarEdge 81,5 kWp)'), 'region med parentes är ingen gata');
ng_ok(jeab_name_address_street_kind('Skåne (SolarEdge 81,5 kWp)') === 'unknown', 'regiongatan är unknown');
$floor = jeab_name_address_normalize_place('Evenemangsgatan 24 4tr', '21219', 'Malmö');
ng_ok($floor['street'] === 'Evenemangsgatan 24' && $floor['city'] === 'Malmö', 'gatan behåller husnumret');
$unit = jeab_name_address_normalize_place('Storgatan 12 lgh 2', '', '');
ng_ok($unit['street'] === 'Storgatan 12 lgh 2', 'lägenhet får stå kvar');
ng_ok(jeab_name_address_street_kind('Behållsgatan 2') === 'real', 'riktig gata är real');
ng_ok(jeab_name_address_street_kind('Mellan Lund och Eslöv') === 'placeholder', 'gata utan siffra är en platshållare');
ng_ok(jeab_name_address_street_kind('Malmö') === 'placeholder', 'ort som gata är en platshållare');

$kept = jeab_kontakt_fill_blanks([
    'street' => 'Skåne (SolarEdge 81,5 kWp)',
    'street_key' => '',
    'city' => 'Test',
    'postal_code' => '',
], ['street' => 'Solgatan 9', 'city' => 'Malmö']);
ng_ok((string) ($kept['street'] ?? '') === 'Skåne (SolarEdge 81,5 kWp)', 'unknown gata skrivs inte över');
ng_ok((string) ($kept['city'] ?? '') === 'Malmö', 'platshållarorten Test byts när en ort finns');
$uncleared = jeab_kontakt_fill_blanks([
    'street' => 'Skåne (SolarEdge 81,5 kWp)',
    'street_key' => '',
    'city' => 'Test',
    'postal_code' => '',
], []);
ng_ok((string) ($uncleared['street'] ?? '') === 'Skåne (SolarEdge 81,5 kWp)', 'unknown gata töms inte');
ng_ok((string) ($uncleared['city'] ?? '') === 'Test', 'ort utan ersättning töms inte');
$postalCity = jeab_kontakt_fill_blanks([
    'street' => 'Kund – Elbilsladdare',
    'street_key' => '',
    'city' => '1025',
    'postal_code' => '21219',
], []);
ng_ok((string) ($postalCity['street'] ?? '') === 'Kund – Elbilsladdare', 'produkttitel töms inte utan ersättning');
ng_ok((string) ($postalCity['city'] ?? '') === 'Malmö', 'postnumret ersätter sifferorten');
$realKept = jeab_kontakt_fill_blanks([
    'street' => 'Behållsgatan 2',
    'street_key' => 'behallsgatan 2',
    'city' => 'Teststad',
    'postal_code' => '11122',
], ['street' => 'Solgatan 9', 'city' => 'Stockholm', 'postal_code' => '11122']);
ng_ok((string) ($realKept['street'] ?? '') === 'Behållsgatan 2', 'real gata skrivs inte över');
ng_ok((string) ($realKept['city'] ?? '') === 'Teststad', 'okänd ort skrivs inte över');
$betweenKept = jeab_kontakt_fill_blanks([
    'street' => 'Mellan Lund och Eslöv',
    'street_key' => '',
    'city' => 'Lund',
    'postal_code' => '',
], []);
ng_ok((string) ($betweenKept['street'] ?? '') === 'Mellan Lund och Eslöv', 'sifferlös gata töms inte');
$betweenReplaced = jeab_kontakt_fill_blanks([
    'street' => 'Mellan Lund och Eslöv',
    'street_key' => '',
    'city' => 'Lund',
    'postal_code' => '',
], ['street' => 'Pråmvägen 11', 'city' => 'Malmö', 'postal_code' => '21124']);
ng_ok((string) ($betweenReplaced['street'] ?? '') === 'Pråmvägen 11', 'sifferlös gata byts mot en numrerad gata');

$now = jeab_now();
$phoneSeq = 0;
$make = static function (string $name, string $email) use (&$phoneSeq): array {
    $phoneSeq++;
    $row = jeab_kontakt_find_or_create([
        'name' => $name,
        'email' => $email,
        'phone' => '070100' . str_pad((string) $phoneSeq, 4, '0', STR_PAD_LEFT),
    ], false);
    ng_ok(is_array($row) && trim((string) ($row['id'] ?? '')) !== '', 'kontakt ' . $email);

    return $row;
};
$set = static function (string $id, string $name, string $street, string $postal, string $city): void {
    jeab_kontakter_mutate(static function (array &$contacts) use ($id, $name, $street, $postal, $city): void {
        $contacts[$id]['owners'][0]['name'] = $name;
        $contacts[$id]['street'] = $street;
        $contacts[$id]['street_key'] = '';
        $contacts[$id]['postal_code'] = $postal;
        $contacts[$id]['city'] = $city;
    });
};
$link = static function (string $id, string $email, string $name, string $street, string $postal, string $city) use ($store, $now): int {
    $row = $store->insertReminder([
        'title' => $email,
        'customer_name' => $name,
        'email' => $email,
        'address' => $street,
        'postal' => $postal,
        'ort' => $city,
        'company' => 'malmo',
        'note' => "Kund: Kund\n",
        'due_at' => '2026-10-24 12:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    ng_ok(jeab_kontakt_stamp_reminder($store, (int) $row['id'], $id), 'kortet är länkat');

    return (int) $row['id'];
};

$one = $make('Kund', 'guard.one@example.test');
$two = $make('Kund', 'guard.two@example.test');
$oneId = (string) $one['id'];
$twoId = (string) $two['id'];
$set($oneId, 'guard.one@example.test', '', '', 'The Netherlands');
$set($twoId, 'guard.two@example.test', '', '', '');
$link($oneId, 'guard.one@example.test', 'Anna Guard', 'Guardgatan 1', '21219', 'Malmö');
$link($twoId, 'guard.two@example.test', 'Bo Guard', 'Guardgatan 2', '21220', 'Malmö');
$wet = jeab_kontakter_name_address_backfill([
    'dry' => 0,
    'contact_ids' => $oneId . ', ' . $twoId . '-saknas',
], $store);
ng_ok(!empty($wet['ok']) && (int) ($wet['applied'] ?? 0) === 1, 'bara listade kontakter skrivs');
ng_ok((string) (jeab_kontakt_get($oneId)['street'] ?? '') === 'Guardgatan 1', 'listad kontakt får gatan');
ng_ok((string) (jeab_kontakt_get($oneId)['city'] ?? '') === 'Malmö', 'landet byts mot postorten');
ng_ok((string) (jeab_kontakt_get($twoId)['street'] ?? '') === '', 'olistad kontakt rörs inte');

$names = ['test test', 'demo testing', 'FEJK', 'mms sdfgn', 'hämta bil', 'Test Support Einstein'];
$skipped = [];
foreach ($names as $i => $name) {
    $row = $make($name, 'guard.skip' . $i . '@example.test');
    $id = (string) $row['id'];
    $set($id, $name, '', '', '');
    $link($id, 'guard.skip' . $i . '@example.test', 'Riktigt Namn', 'Skipgatan ' . ($i + 1), '21219', 'Malmö');
    $skipped[$name] = $id;
}
$drySkip = jeab_kontakter_name_address_backfill(['dry' => 1, 'limit' => 200], $store);
$dryIds = [];
foreach ((array) ($drySkip['contacts'] ?? []) as $row) {
    if (is_array($row)) {
        $dryIds[] = (string) ($row['id'] ?? '');
    }
}
foreach ($skipped as $name => $id) {
    ng_ok(!in_array($id, $dryIds, true), $name . ' hoppas över');
}
$listed = (string) $skipped['test test'];
$listedPlan = jeab_kontakter_name_address_backfill([
    'dry' => 1,
    'contact_ids' => $listed,
], $store);
$listedIds = [];
foreach ((array) ($listedPlan['contacts'] ?? []) as $row) {
    if (is_array($row)) {
        $listedIds[] = (string) ($row['id'] ?? '');
    }
}
ng_ok($listedIds === [$listed], 'listad testkontakt får en plan');

$floorContact = $make('Eva Våning', 'guard.floor@example.test');
$floorId = (string) $floorContact['id'];
$set($floorId, 'Eva Våning', 'Evenemangsgatan 24 4tr', '21219', 'Malmö');
$floorPlan = jeab_name_address_plan_contact($floorId, jeab_kontakt_get($floorId) ?: []);
ng_ok(is_array($floorPlan) && ($floorPlan['proposed']['street'] ?? '') === 'Evenemangsgatan 24', 'planen behåller husnumret och tappar 4tr');

$region = $make('Region Person', 'guard.region@example.test');
$regionId = (string) $region['id'];
$set($regionId, 'Region Person', 'Skåne (SolarEdge 81,5 kWp)', '21219', 'Malmö');
$regionReminder = $link($regionId, 'guard.region@example.test', 'Region Person', 'Solgatan 8', '21219', 'Malmö');
$regionPlan = jeab_name_address_plan_contact($regionId, jeab_kontakt_get($regionId) ?: []);
ng_ok($regionPlan === null || ($regionPlan['proposed']['street'] ?? '') === 'Skåne (SolarEdge 81,5 kWp)', 'källans gata ersätter inte unknown');

$explainDenied = jeab_kontakter_name_address_explain_dispatch(['action' => 'kontakter.name_address_explain', 'email' => 'guard.one@example.test']);
$_SERVER['HTTP_AUTHORIZATION'] = '';
$explainDenied = jeab_kontakter_name_address_explain_dispatch(['action' => 'kontakter.name_address_explain', 'email' => 'guard.one@example.test']);
ng_ok((int) ($explainDenied['status'] ?? 0) === 401, 'förklaring kräver token');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-name-guard';
$byEmail = jeab_kontakter_name_address_explain_dispatch([
    'action' => 'kontakter.name_address_explain',
    'email' => 'guard.region@example.test',
]);
ng_ok(!empty($byEmail['ok']) && (string) ($byEmail['contact_id'] ?? '') === $regionId, 'e-post hittar kontakten');
$foundStreet = false;
foreach ((array) ($byEmail['sources'] ?? []) as $source) {
    if (is_array($source) && (string) ($source['street'] ?? '') === 'Solgatan 8') {
        $foundStreet = true;
    }
}
ng_ok($foundStreet, 'förklaringen visar källans gata');
$why = implode("\n", (array) ($byEmail['why'] ?? []));
ng_ok(str_contains($why, 'unknown'), 'förklaringen säger varför gatan inte fylldes');
$byReminder = jeab_kontakter_name_address_explain(['reminder_id' => $regionReminder]);
ng_ok((string) ($byReminder['contact_id'] ?? '') === $regionId && (int) ($byReminder['reminder_id'] ?? 0) === $regionReminder, 'påminnelse hittar kontakten');
$pnrReminder = $store->insertReminder([
    'title' => 'pnr',
    'customer_name' => '19900101-1234',
    'email' => 'guard.region@example.test',
    'address' => 'Solgatan 8',
    'postal' => '21219',
    'ort' => 'Malmö',
    'company' => 'malmo',
    'contact_id' => $regionId,
    'note' => "Personnummer: 19900101-1234\n",
    'due_at' => '2026-10-24 13:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
ng_ok(jeab_kontakt_stamp_reminder($store, (int) $pnrReminder['id'], $regionId), 'pnr-kortet är länkat');
$masked = jeab_kontakter_name_address_explain(['reminder_id' => (int) $pnrReminder['id']]);
$encoded = json_encode($masked, JSON_UNESCAPED_UNICODE) ?: '';
ng_ok(!str_contains($encoded, '19900101-1234') && str_contains($encoded, '[pnr]'), 'förklaringen maskerar personnummer');
ng_ok(!str_contains($encoded, 'Personnummer'), 'förklaringen tar inte med anteckningen');

$secret = 'HEMLIGT-' . bin2hex(random_bytes(8));
$secretDir = dirname(jeab_einstein_webhook_secret_path());
if (!is_dir($secretDir)) {
    mkdir($secretDir, 0775, true);
}
file_put_contents(jeab_einstein_webhook_secret_path(), json_encode(['url' => 'https://hooks.flowstack.example.test/bug', 'key' => $secret]));
jeab_log_app_error('guard', 'rad 19900101-1234 ' . $secret, ['no_webhook' => true, 'skip_request' => true]);
$logged = null;
foreach (jeab_app_errors_read(20) as $row) {
    if ((string) ($row['surface'] ?? '') === 'guard') {
        $logged = $row;
    }
}
ng_ok(is_array($logged) && (string) ($logged['fingerprint'] ?? '') !== '', 'felloggen har ett fingeravtryck');
$fp = (string) ($logged['fingerprint'] ?? '');
$got = jeab_fellogg_get(['fingerprint' => substr($fp, 0, 8)]);
$gotJson = json_encode($got, JSON_UNESCAPED_UNICODE) ?: '';
ng_ok(!empty($got['ok']) && (string) ($got['fingerprint'] ?? '') === $fp, 'prefix hämtar raden');
ng_ok((int) ($got['line'] ?? 0) > 0 && str_contains((string) ($got['file'] ?? ''), '.php'), 'raden har fil och radnummer');
ng_ok(str_contains((string) ($got['message'] ?? ''), '[pnr]') && !str_contains($gotJson, '19900101-1234'), 'meddelandet maskerar personnummer');
ng_ok(!str_contains($gotJson, $secret) && !isset($got['stack']), 'svaret har ingen hemlighet och ingen stack');
$miss = jeab_fellogg_get(['fingerprint' => 'aaaaaaaa']);
ng_ok(!empty($miss['ok']) && (int) ($miss['count'] ?? 1) === 0 && (string) ($miss['message'] ?? 'x') === '', 'okänt fingeravtryck är tomt');
$bad = jeab_fellogg_get(['fingerprint' => 'kort']);
ng_ok(empty($bad['ok']) && (int) ($bad['status'] ?? 0) === 400, 'för kort fingeravtryck avvisas');

$onlyFirst = $make('Kund', 'lirije@example.test');
$onlyFirstId = (string) $onlyFirst['id'];
$set($onlyFirstId, 'lirije@example.test', '', '', '');
$link($onlyFirstId, 'lirije@example.test', 'Lirije', 'Ängavångsgatan 79, 254 74 Ödåkra', '', '');
$onlyPlan = jeab_name_address_plan_contact($onlyFirstId, jeab_kontakt_get($onlyFirstId) ?: []);
ng_ok(is_array($onlyPlan) && ($onlyPlan['proposed']['name'] ?? '') === 'Lirije', 'förnamnet ersätter mejladressen');
ng_ok(is_array($onlyPlan) && ($onlyPlan['proposed']['city'] ?? '') === 'Ödåkra', 'planen behåller den utskrivna postorten');
ng_ok(is_array($onlyPlan) && ($onlyPlan['proposed']['street'] ?? '') === 'Ängavångsgatan 79', 'planen tar gatan före postorten');
ng_ok(is_array($onlyPlan) && ($onlyPlan['proposed']['postal_code'] ?? '') === '25474', 'planen tar postnumret ur adressen');

$_SERVER['HTTP_AUTHORIZATION'] = '';
$findDenied = jeab_kontakter_find_dispatch(['action' => 'kontakter.find', 'name' => 'Lirije']);
ng_ok((int) ($findDenied['status'] ?? 0) === 401, 'sökning kräver token');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-name-guard';
$shortFind = jeab_kontakter_find_dispatch(['action' => 'kontakter.find', 'name' => 'Li']);
ng_ok(empty($shortFind['ok']) && (int) ($shortFind['status'] ?? 0) === 400, 'kortare än tre tecken avvisas');
jeab_kontakter_mutate(static function (array &$contacts) use ($onlyFirstId): void {
    $contacts[$onlyFirstId]['owners'][0]['personnummer'] = '19900101-1234';
});
$found = jeab_kontakter_find_dispatch(['action' => 'kontakter.find', 'q' => 'liri']);
ng_ok(!empty($found['ok']), 'sökningen svarar');
$foundRow = null;
foreach ((array) ($found['contacts'] ?? []) as $row) {
    if (is_array($row) && (string) ($row['id'] ?? '') === $onlyFirstId) {
        $foundRow = $row;
    }
}
ng_ok(is_array($foundRow) && ($foundRow['name'] ?? '') === 'lirije@example.test', 'träffen har det sparade namnet');
ng_ok(is_array($foundRow) && ($foundRow['city'] ?? '') === '' && ($foundRow['email'] ?? '') === 'lirije@example.test', 'träffen har e-post och ort');
$foundKeys = is_array($foundRow) ? array_keys($foundRow) : [];
sort($foundKeys);
ng_ok($foundKeys === ['city', 'email', 'id', 'name', 'street'], 'träffen har bara id, namn, e-post, gata och ort');
$foundJson = json_encode($found, JSON_UNESCAPED_UNICODE) ?: '';
ng_ok(!str_contains($foundJson, '19900101-1234') && !str_contains($foundJson, 'personnummer'), 'sökningen tar inte med personnummer');
$none = jeab_kontakter_find(['name' => 'Zzqqq']);
ng_ok(!empty($none['ok']) && ($none['contacts'] ?? null) === [], 'ingen träff är ett tomt svar');
for ($i = 1; $i <= 21; $i++) {
    $make(sprintf('Qqqfind %02d', $i), sprintf('qqqfind%02d@example.test', $i));
}
$capped = jeab_kontakter_find(['name' => 'Qqqfind']);
ng_ok(!empty($capped['ok']) && count((array) ($capped['contacts'] ?? [])) === 20, 'sökningen stannar vid tjugo');
$cappedNames = array_map(static fn(array $row): string => (string) ($row['name'] ?? ''), (array) ($capped['contacts'] ?? []));
ng_ok($cappedNames[0] === 'Qqqfind 01' && !in_array('Qqqfind 21', $cappedNames, true), 'träffarna sorteras på namn');

$peter = $make('Peter Mathiesen', 'peter.mathiesen@example.test');
$peterId = (string) $peter['id'];
$set($peterId, 'Peter Mathiesen', 'Mellan Lund och Eslöv', '', 'Lund');
$link($peterId, 'peter.mathiesen@example.test', 'Peter Mathiesen', 'Pråmvägen 11', '21124', 'Malmö');
$peterPlan = jeab_name_address_plan_contact($peterId, jeab_kontakt_get($peterId) ?: []);
ng_ok(is_array($peterPlan) && ($peterPlan['proposed']['street'] ?? '') === 'Pråmvägen 11', 'Mellan Lund och Eslöv byts mot Pråmvägen 11');

$lirije = $make('Kund', 'lirije.order@example.test');
$lirijeId = (string) $lirije['id'];
$set($lirijeId, 'lirije.order@example.test', '', '', '');
$lirijeReminder = $link($lirijeId, 'lirije.order@example.test', 'Lirije', 'Ödåkra', '', '');
$ownerOnly = $make('Kund', 'lirije.owner@example.test');
$ownerOnlyId = (string) $ownerOnly['id'];
$set($ownerOnlyId, 'lirije.owner@example.test', '', '', '');
$viaOrder = $make('Kund', 'lirije.via@example.test');
$viaId = (string) $viaOrder['id'];
$set($viaId, 'lirije.via@example.test', '', '', '');
$looseReminder = $store->insertReminder([
    'title' => 'lirije.via@example.test',
    'customer_name' => 'Lirije Hoxha',
    'email' => 'lirije.via@example.test',
    'address' => 'Exempelgatan 3, 254 74 Ödåkra',
    'company' => 'malmo',
    'note' => "Kund: Kund\n",
    'due_at' => '2026-10-24 12:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$looseId = (int) ($looseReminder['id'] ?? 0);
ng_ok($looseId > 0 && trim((string) ($store->getReminder($looseId)['contact_id'] ?? '')) === '', 'påminnelsen saknar kontakt');
$GLOBALS['_JEAB_PURCHASE_LIST'] = [
    (string) $lirijeReminder => [
        'id' => $lirijeReminder,
        'reminder_id' => $lirijeReminder,
        'contact_id' => $lirijeId,
        'customer_name' => 'Lirije Hoxha',
        'name' => 'Lirije Hoxha',
        'address' => 'Ängavångsgatan 79, 254 74 Ödåkra',
        'updated_at' => $now,
    ],
    '147' => [
        'id' => 147,
        'reminder_id' => 0,
        'contact_id' => $ownerOnlyId,
        'owners' => [[
            'name' => 'Lirije Hoxha',
            'address' => 'Ängavångsgatan 79, 254 74 Ödåkra',
        ]],
        'updated_at' => $now,
    ],
    (string) $looseId => [
        'id' => $looseId,
        'reminder_id' => $looseId,
        'contact_id' => $viaId,
        'updated_at' => $now,
    ],
];
$lirijePlan = jeab_name_address_plan_contact($lirijeId, jeab_kontakt_get($lirijeId) ?: []);
ng_ok(is_array($lirijePlan) && ($lirijePlan['proposed']['name'] ?? '') === 'Lirije Hoxha', 'orderns kundnamn fylls');
ng_ok(is_array($lirijePlan) && ($lirijePlan['proposed']['street'] ?? '') === 'Ängavångsgatan 79', 'orderns gata fylls');
ng_ok(is_array($lirijePlan) && ($lirijePlan['proposed']['postal_code'] ?? '') === '25474', 'orderns postnummer fylls');
ng_ok(is_array($lirijePlan) && ($lirijePlan['proposed']['city'] ?? '') === 'Ödåkra', 'orderns postort fylls');
$lirijeExplain = jeab_kontakter_name_address_explain(['contact_id' => $lirijeId]);
$lirijeWhy = implode("\n", (array) ($lirijeExplain['why'] ?? []));
ng_ok(str_contains($lirijeWhy, 'paminnelse ' . $lirijeReminder . ': no_house_number'), 'påminnelsen utan husnummer syns');
$orderStreet = '';
foreach ((array) ($lirijeExplain['sources'] ?? []) as $sourceRow) {
    if (is_array($sourceRow) && (string) ($sourceRow['source'] ?? '') === 'order') {
        $orderStreet = (string) ($sourceRow['street'] ?? '');
    }
}
ng_ok(str_contains($orderStreet, 'Ängavångsgatan 79'), 'ordern är en källa');
$ownerPlan = jeab_name_address_plan_contact($ownerOnlyId, jeab_kontakt_get($ownerOnlyId) ?: []);
ng_ok(is_array($ownerPlan) && ($ownerPlan['proposed']['street'] ?? '') === 'Ängavångsgatan 79' && ($ownerPlan['proposed']['city'] ?? '') === 'Ödåkra', 'ägarens adress på ordern används');
ng_ok(is_array($ownerPlan) && ($ownerPlan['proposed']['name'] ?? '') === 'Lirije Hoxha', 'ägarens namn på ordern används');
$viaPlan = jeab_name_address_plan_contact($viaId, jeab_kontakt_get($viaId) ?: []);
ng_ok(is_array($viaPlan) && ($viaPlan['proposed']['street'] ?? '') === 'Exempelgatan 3', 'orderns påminnelse är en källa');
$viaExplain = jeab_kontakter_name_address_explain(['contact_id' => $viaId]);
$viaSources = array_map(static fn ($row): string => is_array($row) ? (string) ($row['source'] ?? '') : '', (array) ($viaExplain['sources'] ?? []));
ng_ok(in_array('paminnelse', $viaSources, true) && in_array('order', $viaSources, true), 'både order och påminnelse listas');

echo "OK  gardet är grönt\n";
