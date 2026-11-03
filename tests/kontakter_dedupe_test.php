<?php

declare(strict_types=1);

/**
 * Dubblettregler med påhittade personer. Inga riktiga kunder.
 */

$tmp = sys_get_temp_dir() . '/jeab-kontakter-dedupe-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => [
        'lookup_provider' => 'rot',
        'postal_towns' => ['Exempelorten' => 'Exempelkommunen'],
    ],
    'staff' => [
        ['name' => 'Stina Exempel', 'email' => 'stina.exempel@example.test'],
    ],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function kd(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function kd_pnr(): string
{
    $nine = '900101123';
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
    return '19900101-123' . $check;
}

/** @param array<string, mixed> $hint */
function kd_put(array $hint): array
{
    $row = jeab_kontakt_blank($hint);
    if (trim((string) ($hint['created_at'] ?? '')) !== '') {
        $row['created_at'] = (string) $hint['created_at'];
        $row['updated_at'] = (string) ($hint['updated_at'] ?? $hint['created_at']);
    }
    jeab_kontakter_mutate(static function (array &$contacts) use ($row): array {
        $contacts[(string) $row['id']] = $row;
        return $row;
    });
    return $row;
}

function kd_file_hash(): string
{
    $path = jeab_kontakter_path();
    return is_file($path) ? hash('sha256', (string) file_get_contents($path)) : '';
}

$vagA = jeab_kontakt_address_identity('EXEMPEL-VÄGEN 12A', 'Exempelby');
$vagB = jeab_kontakt_address_identity('exempelv. 12 a', 'Exempelby kommun');
$vagC = jeab_kontakt_address_identity('Exempelväg 12a', 'Exempelby');
kd($vagA['key'] !== '' && $vagA['key'] === $vagB['key'] && $vagA['key'] === $vagC['key'], 'väg, vägen, v. och 12A är samma adress');
kd(jeab_kontakt_address_identity('Exempelvägen 12', 'Exempelby')['key'] !== $vagA['key'], '12 är inte 12A');
$gataA = jeab_kontakt_address_identity('Exempelgatan 8', 'Exempelby');
$gataB = jeab_kontakt_address_identity('Exempelg. 8', 'Exempelby');
$gataC = jeab_kontakt_address_identity('Exempelgata 8', 'Exempelby');
kd($gataA['key'] !== '' && $gataA['key'] === $gataB['key'] && $gataA['key'] === $gataC['key'], 'gata, gatan och g. är samma adress');
kd(jeab_kontakt_city_key('Exempelorten') !== '' && jeab_kontakt_city_key('Exempelorten') === jeab_kontakt_city_key('Exempelkommunen'), 'postort och kommun är samma ort');
$apt1101 = jeab_kontakt_address_parts('Husvägen 5 lgh 1101');
$apt1102 = jeab_kontakt_address_parts('Husvägen 5 lägenhet 1102');
kd($apt1101['apartment'] === '1101' && $apt1102['apartment'] === '1102' && $apt1101['street_key'] === $apt1102['street_key'], 'lägenhetsnummer skiljs från gatunummer');
kd(jeab_kontakt_email_is_staff_or_platform('stina.exempel@example.test'), 'personalens e-post är inte en kundnyckel');
kd(jeab_kontakt_email_is_staff_or_platform('info@example.test'), 'info@ är en plattformsadress');
kd(!jeab_kontakt_email_is_staff_or_platform('kalle@example.test'), 'kalle@example.test är en kundadress');

$pnr = kd_pnr();
$kim1 = kd_put([
    'name' => 'Kim Exempel',
    'email' => 'kim.a@example.test',
    'phone' => '070-1000001',
    'street' => 'EXEMPEL-VÄGEN 12A',
    'street_key' => jeab_kontakt_street_key('EXEMPEL-VÄGEN 12A'),
    'postal_code' => '11111',
    'city' => 'Exempelby',
]);
$kim2 = kd_put([
    'name' => 'Kim Exempel',
    'email' => 'kim.b@example.test',
    'phone' => '070-1000002',
    'street' => 'exempelv. 12 a',
    'street_key' => jeab_kontakt_street_key('exempelv. 12 a'),
    'postal_code' => '11111',
    'city' => 'Exempelby kommun',
]);
$kim3 = kd_put([
    'name' => 'Kim Exempel',
    'email' => 'kim.c@example.test',
    'phone' => '070-1000003',
    'street' => 'Exempelväg 12a',
    'street_key' => jeab_kontakt_street_key('Exempelväg 12a'),
    'postal_code' => '22222',
    'city' => 'Exempelby',
]);
$noa1 = kd_put([
    'name' => 'Noa Exempel',
    'email' => 'noa.a@example.test',
    'phone' => '070-2000001',
    'street' => 'Exempelgatan 8',
    'street_key' => jeab_kontakt_street_key('Exempelgatan 8'),
    'city' => 'Exempelby',
]);
$noa2 = kd_put([
    'name' => 'Noa Exempel',
    'email' => 'noa.b@example.test',
    'phone' => '070-2000002',
    'street' => 'Exempelg. 8',
    'street_key' => jeab_kontakt_street_key('Exempelg. 8'),
    'city' => 'Exempelby',
]);
$mio1 = kd_put([
    'name' => 'Mio Exempel',
    'email' => 'mio.a@example.test',
    'phone' => '070-3000001',
    'street' => 'Kartvägen 2',
    'street_key' => jeab_kontakt_street_key('Kartvägen 2'),
    'city' => 'Exempelorten',
]);
$mio2 = kd_put([
    'name' => 'Mio Exempel',
    'email' => 'mio.b@example.test',
    'phone' => '070-3000002',
    'street' => 'Kartvägen 2',
    'street_key' => jeab_kontakt_street_key('Kartvägen 2'),
    'city' => 'Exempelkommunen',
]);
$old = kd_put([
    'name' => 'Kalle Exempel',
    'email' => 'kalle@example.test',
    'phone' => '070-0000000',
    'personnummer' => $pnr,
    'street' => 'Nya Exempelgatan 9',
    'street_key' => jeab_kontakt_street_key('Nya Exempelgatan 9'),
    'postal_code' => '33333',
    'city' => 'Fiktivort',
    'created_at' => '2026-01-10 08:00:00',
]);
jeab_kontakter_mutate(static function (array &$contacts) use ($old): array {
    $contacts[(string) $old['id']]['fortnox_customer'] = '9001';
    $contacts[(string) $old['id']]['rot_link'] = 'https://example.test/rot/fiktiv-ansokan';
    return $contacts[(string) $old['id']];
});
$new = kd_put([
    'name' => 'Kalle Exempel',
    'email' => 'Kalle@Example.TEST',
    'phone' => '070-1112233',
    'street' => 'Nya Exempelgatan 9',
    'street_key' => jeab_kontakt_street_key('Nya Exempelgatan 9'),
    'postal_code' => '33333',
    'city' => 'Exempelby',
    'created_at' => '2026-08-01 08:00:00',
]);
$lgh1 = kd_put([
    'name' => 'Lgh Ett',
    'email' => 'lgh.ett@example.test',
    'phone' => '070-4000001',
    'street' => 'Husvägen 5 lgh 1101',
    'street_key' => jeab_kontakt_street_key('Husvägen 5'),
    'city' => 'Exempelby',
]);
$lgh2 = kd_put([
    'name' => 'Lgh Två',
    'email' => 'lgh.tva@example.test',
    'phone' => '070-4000002',
    'street' => 'Husvägen 5 lägenhet 1102',
    'street_key' => jeab_kontakt_street_key('Husvägen 5'),
    'city' => 'Exempelby',
]);
$lgh3a = kd_put([
    'name' => 'Lgh Tre',
    'email' => 'lgh.tre.a@example.test',
    'phone' => '070-4000003',
    'street' => 'Husvägen 5 lgh 1103',
    'street_key' => jeab_kontakt_street_key('Husvägen 5'),
    'city' => 'Exempelby',
]);
$lgh3b = kd_put([
    'name' => 'Lgh Tre',
    'email' => 'lgh.tre.b@example.test',
    'phone' => '070-4000004',
    'street' => 'Husv. 5 lägenhet 1103',
    'street_key' => jeab_kontakt_street_key('Husv. 5'),
    'city' => 'Exempelby',
]);
$lghBare = kd_put([
    'name' => 'Lgh Utan',
    'email' => 'lgh.utan@example.test',
    'phone' => '070-4000005',
    'street' => 'Husvägen 5',
    'street_key' => jeab_kontakt_street_key('Husvägen 5'),
    'city' => 'Exempelby',
]);
$brf = kd_put([
    'name' => 'BRF Exempelhus',
    'email' => 'styrelse@example.test',
    'phone' => '070-5000001',
    'street' => 'Föreningsvägen 1',
    'street_key' => jeab_kontakt_street_key('Föreningsvägen 1'),
    'city' => 'Exempelby',
    'kind' => 'company',
]);
$brfPerson = kd_put([
    'name' => 'Ada Exempel',
    'email' => 'ada.exempel@example.test',
    'phone' => '070-5000002',
    'street' => 'Foreningsvagen 1',
    'street_key' => jeab_kontakt_street_key('Foreningsvagen 1'),
    'city' => 'Exempelby',
]);
$staffA = kd_put([
    'name' => 'Stina Exempel',
    'email' => 'stina.exempel@example.test',
    'phone' => '070-6000001',
    'street' => 'Personalvägen 1',
    'street_key' => jeab_kontakt_street_key('Personalvägen 1'),
    'city' => 'Exempelby',
]);
$staffB = kd_put([
    'name' => 'Stina Exempel',
    'email' => 'Stina.Exempel@Example.TEST',
    'phone' => '070-6000002',
    'street' => 'Personalvägen 2',
    'street_key' => jeab_kontakt_street_key('Personalvägen 2'),
    'city' => 'Exempelby',
]);
$infoA = kd_put([
    'name' => 'Info Exempel',
    'email' => 'info@example.test',
    'phone' => '070-7000001',
    'street' => 'Plattformsvägen 1',
    'street_key' => jeab_kontakt_street_key('Plattformsvägen 1'),
    'city' => 'Exempelby',
]);
$infoB = kd_put([
    'name' => 'Info Exempel',
    'email' => 'INFO@example.test',
    'phone' => '070-7000002',
    'street' => 'Plattformsvägen 2',
    'street_key' => jeab_kontakt_street_key('Plattformsvägen 2'),
    'city' => 'Exempelby',
]);

$store = jeab_store();
$oldReminder = jeab_create_reminder($store, 'Gammal fiktiv påminnelse', '2026-01-15 09:00:00', 'påhittad', null, [
    'customer' => 'Kalle Exempel',
    'email' => 'kalle@example.test',
    'phone' => '070-0000000',
    'address' => 'Exempelvägen 3, Exempelby',
    'company' => 'jarfalla',
    'who' => 'Elin',
]);
$store->saveReminder((int) $oldReminder['id'], [
    'contact_id' => (string) $old['id'],
    'created_at' => '2026-01-15 09:00:00',
    'updated_at' => '2026-01-15 09:00:00',
    'due_at' => '2026-01-15 09:00:00',
]);
$purchasePdo = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchasePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchasePdo;
jeab_purchase_put([
    'reminder_id' => (int) $oldReminder['id'],
    'kind' => 'laddbox',
    'name' => 'Kalle Exempel',
    'email' => 'kalle@example.test',
    'address' => 'Exempelvägen 3',
    'ort' => 'Exempelby',
    'company' => 'jarfalla',
    'contact_id' => (string) $old['id'],
    'fortnox_customer' => '9001',
    'fortnox_order' => '10001',
    'fortnox_invoice' => '20001',
    'closed_at' => '2026-01-20 10:00:00',
    'updated_at' => '2026-01-20 10:00:00',
    'created_at' => '2026-01-16 10:00:00',
]);
$customer = jeab_find_or_create_customer($store, 'Kalle Exempel');
$booking = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Kalle Exempel',
    'email' => 'kalle@example.test',
    'phone' => '070-0000000',
    'address' => 'Exempelvägen 3, Exempelby',
    'notes' => 'fiktiv bokning',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-01-20 09:00:00',
    'ends_at' => '2026-01-20 10:00:00',
    'status' => 'booked',
    'created_at' => '2026-01-10 09:00:00',
    'updated_at' => '2026-01-10 09:00:00',
]);
$store->saveBooking((int) $booking['id'], ['contact_id' => (string) $old['id']]);
$newReminder = jeab_create_reminder($store, 'Ny fiktiv påminnelse', '2026-09-02 09:00:00', 'påhittad', null, [
    'customer' => 'Kalle Exempel',
    'email' => 'kalle@example.test',
    'phone' => '070-1112233',
    'address' => 'Nya Exempelgatan 9, Exempelby',
    'company' => 'jarfalla',
    'who' => 'Elin',
]);
$store->saveReminder((int) $newReminder['id'], [
    'contact_id' => (string) $new['id'],
    'created_at' => '2026-09-02 09:00:00',
    'updated_at' => '2026-09-02 09:00:00',
    'due_at' => '2026-09-02 09:00:00',
]);
jeab_purchase_put([
    'reminder_id' => (int) $newReminder['id'],
    'kind' => 'batteri',
    'name' => 'Kalle Exempel',
    'email' => 'kalle@example.test',
    'address' => 'Nya Exempelgatan 9',
    'ort' => 'Exempelby',
    'company' => 'jarfalla',
    'contact_id' => (string) $new['id'],
    'updated_at' => '2026-09-02 11:00:00',
    'created_at' => '2026-09-02 11:00:00',
]);
// Anteckningar finns bara i SQL; testet kör JSON-läge (JEAB_DISABLE_SQLITE=1).
$notesSql = jeab_notes_store_pdo() instanceof PDO;
$note = !$notesSql ? null : jeab_notes_create(['title' => 'Fiktiv anteckning', 'body' => 'Påhittad rad om Exempelvägen.']);
$notesSql && jeab_notes_mutate(static function (array &$data) use ($note, $old): null {
    foreach ($data['notes'] as $i => $row) {
        if (!is_array($row) || (int) ($row['id'] ?? 0) !== (int) $note['id']) {
            continue;
        }
        $row['contact_id'] = (string) $old['id'];
        $data['notes'][$i] = $row;
    }
    return null;
});
jeab_inkorg_thread_write('trad-exempel', static function (array $thread) use ($old): array {
    $thread['contact_id'] = (string) $old['id'];
    $thread['updated_at'] = '2026-02-01 10:00:00';
    $thread['subject'] = 'Fiktiv tråd';
    return $thread;
});
$hsId = 'hsi-' . substr(hash('sha256', 'fiktiv-exempel'), 0, 16);
$hsConv = [
    'id' => $hsId,
    'contact_id' => (string) $old['id'],
    'company' => 'jarfalla',
    'messages' => [[
        'id' => 'msgexempel01',
        'from' => 'kalle@example.test',
        'from_name' => 'Kalle Exempel',
        'subject' => 'Fiktiv förfrågan',
        'body' => 'Hej, detta är påhittat.',
        'received_at' => '2026-03-01 12:00:00',
    ]],
    'meta' => [
        'contact_id' => (string) $old['id'],
        'updated_at' => '2026-03-01 12:00:00',
        'status' => 'open',
    ],
];
kd(jeab_hs_import_save_conv($hsConv) && jeab_hs_import_index_append(jeab_hs_import_index_row($hsConv)), 'fiktivt Help Scout-ärende sparas');
$inboxPath = jeab_inkorg_dir() . '/contacts.json';
file_put_contents($inboxPath, json_encode(['contacts' => [
    'in-ex' => [
        'name' => 'Kalle Exempel',
        'emails' => ['kalle@example.test'],
        'notes' => 'fiktiv inkorg ' . $old['id'],
        'contact_id' => (string) $old['id'],
    ],
]], JSON_UNESCAPED_UNICODE));

$beforeHash = kd_file_hash();
$logPath = $tmp . '/kontakter-dedupe.log';
$beforeLog = is_file($logPath) ? (string) file_get_contents($logPath) : null;
$beforeCount = count(jeab_kontakter_load());
$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-kd-test']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_kontakter_dedupe_dispatch(['action' => 'kontakter.dedupe']);
kd(($denied['status'] ?? 0) === 401 && ($denied['ok'] ?? true) === false, 'kontakter.dedupe utan token ger 401');
kd(kd_file_hash() === $beforeHash && (is_file($logPath) ? (string) file_get_contents($logPath) : null) === $beforeLog, 'nekad hook skriver inget');

$dry = jeab_kontakter_dedupe([]);
kd(($dry['dry'] ?? false) === true, 'dry saknas betyder dry=1');
kd(($dry['counts']['merges'] ?? 0) >= 4, 'torrkörning listar föreslagna hopslagningar');
kd(kd_file_hash() === $beforeHash && (is_file($logPath) ? (string) file_get_contents($logPath) : null) === $beforeLog, 'dry=1 skriver varken kontakter eller logg');
$dryPairs = [];
foreach ((array) ($dry['merges'] ?? []) as $merge) {
    $dryPairs[(string) ($merge['from'] ?? '') . '>' . (string) ($merge['to'] ?? '')] = (string) ($merge['rule'] ?? '');
    kd((string) ($merge['from'] ?? '') !== '' && (string) ($merge['to'] ?? '') !== '' && (string) ($merge['reason'] ?? '') !== '', 'varje förslag har id, id och orsak');
}
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
kd(is_string($dryJson) && !str_contains($dryJson, $pnr) && !str_contains($dryJson, preg_replace('/\D+/', '', $pnr) ?: '19900101123'), 'torrkörningen innehåller inte personnummer');
$uncertainReasons = [];
foreach ((array) ($dry['uncertain'] ?? []) as $row) {
    $uncertainReasons[(string) ($row['reason'] ?? '')] = true;
    $ids = (array) ($row['ids'] ?? []);
    kd(count($ids) >= 2 && (string) ($row['rule'] ?? '') !== '', 'osäkert fall listas med id och regel');
}
kd(isset($uncertainReasons['olika lägenhetsnummer']), 'olika lägenhetsnummer slås inte ihop');
kd(isset($uncertainReasons['lägenhetsnummer bara på en kontakt']), 'lägenhetsnummer på bara en sida slås inte ihop');
kd(isset($uncertainReasons['brf eller samfällighet']), 'BRF slås inte ihop med en person');
kd(isset($uncertainReasons['personal eller plattform']), 'personal och plattform slås inte ihop');

$runner = $tmp . '/hook-runner.php';
file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$GLOBALS['_JEAB_DATA_DIR'] = $argv[1];
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $argv[1];
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=1');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $argv[2];
$GLOBALS['_JEAB_RAW_BODY'] = $argv[3];
require $argv[4];
PHP);
$hookOut = [];
$hookCode = 0;
exec(
    'php ' . escapeshellarg($runner) . ' '
    . escapeshellarg($tmp) . ' '
    . escapeshellarg('tok-kd-test') . ' '
    . escapeshellarg('{"action":"kontakter.dubbletter","dry":1}') . ' '
    . escapeshellarg(dirname(__DIR__) . '/hook.php'),
    $hookOut,
    $hookCode
);
$alias = json_decode(implode("\n", $hookOut), true);
kd($hookCode === 0 && is_array($alias) && ($alias['dry'] ?? false) === true && ($alias['counts']['merges'] ?? 0) === ($dry['counts']['merges'] ?? -1), 'kontakter.dubbletter är alias för kontakter.dedupe');
kd(kd_file_hash() === $beforeHash, 'aliasets torrkörning skriver inget');

$rotDir = $tmp . '/rot';
if (!is_dir($rotDir)) {
    mkdir($rotDir, 0775, true);
}
$rotFile = $rotDir . '/ansokan-fiktiv.json';
file_put_contents($rotFile, "{\"id\":\"fiktiv\",\"status\":\"inkomsten\"}\n");
$rotHash = hash('sha256', (string) file_get_contents($rotFile));
$orderBefore = jeab_purchase_get((int) $oldReminder['id']);
$sideHits = ['mail' => 0, 'fortnox' => 0, 'sms' => 0, 'auto_quote' => 0, 'quote' => 0];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts = []) use (&$sideHits): array {
    $sideHits['mail']++;
    return ['ok' => true, 'path' => 'test', 'id' => 'mail-1'];
};
$GLOBALS['JEAB_SMS_SEND'] = static function (string $to = '', string $text = '', ?string $company = null, ?string $fromOverride = null) use (&$sideHits): array {
    $sideHits['sms']++;
    return ['ok' => true, 'id' => 'sms-1'];
};
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $payload = []) use (&$sideHits): array {
    $sideHits['quote']++;
    return ['ok' => true, 'id' => 'quote-1'];
};
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function () use (&$sideHits): array {
    $sideHits['fortnox']++;
    return ['Customer' => ['CustomerNumber' => '9001']];
};
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-kd-test';
$wet = jeab_kontakter_dedupe_dispatch(['action' => 'kontakter.dedupe', 'dry' => 0, 'allow_mass' => 1]);
$expectAddress = 0;
$expectEmail = 0;
foreach ((array) ($dry['merges'] ?? []) as $merge) {
    if ((string) ($merge['rule'] ?? '') === 'address') {
        $expectAddress++;
    } elseif ((string) ($merge['rule'] ?? '') === 'email') {
        $expectEmail++;
    }
}
kd(($wet['ok'] ?? false) === true && ($wet['dry'] ?? true) === false && ($wet['counts']['merges'] ?? 0) === ($dry['counts']['merges'] ?? -1), 'dry=0 genomför torrkörningens förslag');
kd(($wet['counts']['merged_address'] ?? -1) === $expectAddress && $expectAddress > 0, 'dry=0 räknar hopslagningar på adress');
kd(($wet['counts']['merged_email'] ?? -1) === $expectEmail && $expectEmail > 0, 'dry=0 räknar hopslagningar på e-post');
kd(($wet['counts']['removed'] ?? -1) === ($wet['counts']['merges'] ?? -2), 'dry=0 räknar borttagna kontakter');
kd(($wet['counts']['links_moved'] ?? 0) >= ($notesSql ? 7 : 6), 'dry=0 räknar flyttade länkar');
kd($sideHits === ['mail' => 0, 'fortnox' => 0, 'sms' => 0, 'auto_quote' => 0, 'quote' => 0], 'sammanslagningen anropar inte Fortnox, mejl, SMS eller offert');
kd(hash('sha256', (string) file_get_contents($rotFile)) === $rotHash, 'sammanslagningen skriver inte under /rot/');
$contacts = jeab_kontakter_load();
kd(!isset($contacts[$old['id']]) && isset($contacts[$new['id']]), 'äldre e-postkontakten tas bort och den nyare finns kvar');
$kept = $contacts[$new['id']];
kd((string) ($kept['street'] ?? '') === 'Nya Exempelgatan 9' && (string) ($kept['city'] ?? '') === 'Exempelby', 'senaste aktiviteten behåller adressen');
$line = substr(jeab_now(), 0, 10) . ' sammanslagen: Kalle Exempel, Nya Exempelgatan 9, Fiktivort, kalle@example.test, 070-0000000';
kd(str_contains((string) ($kept['ovrigt'] ?? ''), $line), 'Övrigt får den borttagna kontaktens uppgifter');
kd(str_contains((string) ($kept['ovrigt'] ?? ''), 'Fortnox-kund 9001') && str_contains((string) ($kept['ovrigt'] ?? ''), 'ROT https://example.test/rot/fiktiv-ansokan'), 'Övrigt behåller Fortnox-kundnummer och ROT-länk');
kd(!array_key_exists('fortnox_customer', $kept) && !array_key_exists('rot_link', $kept), 'Fortnox- och ROT-pekaren kopieras inte till kontakten som behålls');
$orderAfter = jeab_purchase_get((int) $oldReminder['id']);
kd(is_array($orderAfter) && (string) ($orderAfter['fortnox_customer'] ?? '') === '9001' && (string) ($orderAfter['fortnox_order'] ?? '') === '10001' && (string) ($orderAfter['fortnox_invoice'] ?? '') === '20001', 'orderns Fortnox-nummer lämnas orörda');
kd(is_array($orderBefore) && (string) ($orderBefore['fortnox_customer'] ?? '') === (string) ($orderAfter['fortnox_customer'] ?? '') && (string) ($orderBefore['fortnox_order'] ?? '') === (string) ($orderAfter['fortnox_order'] ?? ''), 'Fortnox-källa på ordern är oförändrad');
kd(!str_contains((string) ($kept['ovrigt'] ?? ''), $pnr) && !str_contains((string) ($kept['ovrigt'] ?? ''), preg_replace('/\D+/', '', $pnr) ?: 'x'), 'Övrigt innehåller inte personnummer');
$keptPnr = '';
foreach ((array) ($kept['owners'] ?? []) as $owner) {
    if (is_array($owner) && trim((string) ($owner['personnummer'] ?? '')) !== '') {
        $keptPnr = (string) $owner['personnummer'];
    }
}
kd($keptPnr !== '' && $keptPnr === jeab_kontakt_pnr_store($pnr), 'personnumret finns kvar på kontakten som behålls');
kd((string) ($store->getReminder((int) $oldReminder['id'])['contact_id'] ?? '') === (string) $new['id'], 'påminnelsen pekar om');
kd((string) ($store->getReminder((int) $newReminder['id'])['contact_id'] ?? '') === (string) $new['id'], 'den nyare påminnelsen stannar');
kd((string) ($store->getBooking((int) $booking['id'])['contact_id'] ?? '') === (string) $new['id'], 'bokningen pekar om');
kd((string) (jeab_purchase_get((int) $oldReminder['id'])['contact_id'] ?? '') === (string) $new['id'], 'stängd order pekar om');
kd((string) (jeab_purchase_get((int) $newReminder['id'])['contact_id'] ?? '') === (string) $new['id'], 'öppen order stannar på den nyare kontakten');
$noteAfter = null;
foreach ($notesSql ? jeab_notes_load()['notes'] : [] as $row) {
    if (is_array($row) && (int) ($row['id'] ?? 0) === (int) $note['id']) {
        $noteAfter = $row;
    }
}
kd(!$notesSql || is_array($noteAfter) && (string) ($noteAfter['contact_id'] ?? '') === (string) $new['id'], 'anteckningen pekar om');
kd((string) (jeab_inkorg_threads_meta()['trad-exempel']['contact_id'] ?? '') === (string) $new['id'], 'inkorgstråden pekar om');
$hsAfter = jeab_hs_import_read_conv($hsId);
kd(is_array($hsAfter) && (string) ($hsAfter['contact_id'] ?? '') === (string) $new['id'] && (string) (($hsAfter['meta']['contact_id'] ?? '')) === (string) $new['id'], 'Help Scout-ärendet pekar om');
$inboxAfter = json_decode((string) file_get_contents($inboxPath), true);
kd(is_array($inboxAfter) && (string) ($inboxAfter['contacts']['in-ex']['contact_id'] ?? '') === (string) $new['id'] && str_contains((string) ($inboxAfter['contacts']['in-ex']['notes'] ?? ''), (string) $new['id']) && !str_contains((string) ($inboxAfter['contacts']['in-ex']['notes'] ?? ''), (string) $old['id']), 'inkorgens kontaktpekare flyttas');

$kimIds = array_values(array_filter([(string) $kim1['id'], (string) $kim2['id'], (string) $kim3['id']], static fn(string $id): bool => isset($contacts[$id])));
kd(count($kimIds) === 2 && isset($contacts[(string) $kim3['id']]), 'annat postnummer slås inte ihop med adressvarianten');
$kimKept = $contacts[$kimIds[0]];
kd(str_contains((string) ($kimKept['ovrigt'] ?? ''), 'sammanslagen:'), 'adresshopslagningen lämnar spår i Övrigt');
kd(count(array_filter([(string) $noa1['id'], (string) $noa2['id']], static fn(string $id): bool => isset($contacts[$id]))) === 1, 'gata och g. blir en kontakt');
kd(count(array_filter([(string) $mio1['id'], (string) $mio2['id']], static fn(string $id): bool => isset($contacts[$id]))) === 1, 'postort och kommun blir en kontakt');
$sameApt = array_values(array_filter([(string) $lgh3a['id'], (string) $lgh3b['id']], static fn(string $id): bool => isset($contacts[$id])));
kd(count($sameApt) === 1, 'samma lägenhetsnummer slås ihop');
kd(isset($contacts[$lgh1['id']]) && isset($contacts[$lgh2['id']]) && isset($contacts[$lghBare['id']]), 'olika eller saknade lägenhetsnummer ligger kvar');
kd(isset($contacts[$brf['id']]) && isset($contacts[$brfPerson['id']]), 'BRF och person på samma gata ligger kvar');
kd(isset($contacts[$staffA['id']]) && isset($contacts[$staffB['id']]), 'personalens e-post slås inte ihop');
kd(isset($contacts[$infoA['id']]) && isset($contacts[$infoB['id']]), 'plattformens e-post slås inte ihop');

$log = (string) file_get_contents($tmp . '/kontakter-dedupe.log');
kd($log !== '' && str_contains($log, (string) $old['id']) && str_contains($log, (string) $new['id']), 'loggen har båda kontakt-id');
$sawEmail = false;
foreach (array_filter(explode("\n", $log)) as $logLine) {
    $row = json_decode($logLine, true);
    kd(is_array($row) && isset($row['time'], $row['from'], $row['to'], $row['rule']) && !isset($row['personnummer']), 'loggraden har tid, id och regel');
    kd(!str_contains($logLine, $pnr) && !str_contains($logLine, preg_replace('/\D+/', '', $pnr) ?: 'x'), 'loggen innehåller inte personnummer');
    if ((string) ($row['from'] ?? '') === (string) $old['id'] && (string) ($row['to'] ?? '') === (string) $new['id'] && (string) ($row['rule'] ?? '') === 'email') {
        $sawEmail = true;
    }
}
kd($sawEmail, 'e-posthopslagningen loggas');

$afterHash = kd_file_hash();
$afterCount = count(jeab_kontakter_load());
kd($afterCount < $beforeCount, 'hopslagningen minskar antalet kontakter');
$again = jeab_kontakter_dedupe(['dry' => 0]);
kd(($again['counts']['merges'] ?? 1) === 0 && ($again['counts']['merged_address'] ?? -1) === 0 && ($again['counts']['merged_email'] ?? -1) === 0 && ($again['counts']['removed'] ?? -1) === 0 && ($again['counts']['links_moved'] ?? -1) === 0 && kd_file_hash() === $afterHash && count(jeab_kontakter_load()) === $afterCount, 'andra körningen gör inga ändringar');
kd(substr_count((string) file_get_contents($tmp . '/kontakter-dedupe.log'), "\n") === substr_count($log, "\n"), 'andra körningen skriver ingen ny loggrad');
kd(($again['counts']['uncertain'] ?? 0) > 0, 'osäkra fall listas även när inget slås ihop');

$created = jeab_kontakt_find_or_create([
    'name' => 'Future Exempel',
    'email' => 'future@example.test',
    'phone' => '070-2223344',
    'address' => 'Framtidsvägen 7',
    'city' => 'Exempelby',
    'postal_code' => '44444',
]);
kd(is_array($created) && (string) ($created['id'] ?? '') !== '', 'ny adress skapar en kontakt');
$countAfterCreate = count(jeab_kontakter_load());
$reused = jeab_kontakt_find_or_create([
    'name' => 'Future Exempel',
    'email' => 'future@example.test',
    'phone' => '070-2223344',
    'address' => 'Framtidsväg 7',
    'city' => 'Exempelby kommun',
    'postal_code' => '44444',
]);
kd(is_array($reused) && (string) ($reused['id'] ?? '') === (string) $created['id'] && count(jeab_kontakter_load()) === $countAfterCreate, 'väg och vägen återanvänder kontakten');
$fresh = jeab_create_reminder($store, 'Fiktiv påminnelse efter sammanslagning', jeab_today_start(), 'påhittad', null, [
    'customer' => 'Future Exempel',
    'email' => 'future@example.test',
    'phone' => '070-2223344',
    'address' => 'Framtidsväg 7, Exempelby',
    'company' => 'jarfalla',
    'who' => 'Elin',
]);
$attached = jeab_kontakt_attach_reminder($store, $fresh, []);
kd(is_array($attached) && (string) ($attached['id'] ?? '') === (string) $created['id'], 'ny påminnelse kopplas till kontakten som finns');
kd((string) ($store->getReminder((int) $fresh['id'])['contact_id'] ?? '') === (string) $created['id'] && count(jeab_kontakter_load()) === $countAfterCreate, 'kopplingen skapar ingen extra kontakt');

$edited = jeab_kontakt_save_input([
    'id' => (string) $created['id'],
    'street' => 'Framtidsvägen 7',
    'city' => 'Exempelby',
    'postal_code' => '44444',
    'owner_name_1' => 'Future Exempel',
    'email' => 'future@example.test',
    'phone' => '070-2223344',
    'contact_person' => '1',
    'ovrigt' => "Fiktiv notering: inget riktigt kundärende",
]);
kd((string) ($edited['id'] ?? '') === (string) $created['id'] && str_contains((string) (jeab_kontakt_get((string) $created['id'])['ovrigt'] ?? ''), 'Fiktiv notering: inget riktigt kundärende'), 'Övrigt sparas med kontakten');

$_GET['p'] = 'kontakter';
$_GET['id'] = (string) $new['id'];
$_GET['q'] = '';
$body = require dirname(__DIR__) . '/src/pages/kontakter.php';
kd(is_string($body) && str_contains($body, 'Övrigt') && str_contains($body, 'name="ovrigt"') && str_contains($body, 'Historik') && str_contains($body, 'sammanslagen: Kalle Exempel'), 'kontaktvyn visar Övrigt och historiken');
// Kontaktsidan listar inte längre påminnelser; ompekningen prövas ovan via storen.
kd(str_contains($body, 'Nya Exempelgatan 9') && (string) ($store->getReminder((int) $oldReminder['id'])['contact_id'] ?? '') === (string) $new['id'], 'den sammanslagna kontakten visar adressen och äger påminnelsen');
$_GET['id'] = (string) $created['id'];
$futureBody = require dirname(__DIR__) . '/src/pages/kontakter.php';
kd(is_string($futureBody) && str_contains($futureBody, 'Fiktiv notering: inget riktigt kundärende') && (string) ($store->getReminder((int) $fresh['id'])['contact_id'] ?? '') === (string) $created['id'], 'sparat Övrigt syns och den nya påminnelsen hör till kontakten');

require_once dirname(__DIR__) . '/src/fortnox.php';
require_once dirname(__DIR__) . '/src/address_lookup.php';
$effectsBefore = $GLOBALS['_JEAB_KONTAKT_SIDE_EFFECTS'] ?? [];
jeab_kontakt_quiet_enter();
$blockedMail = jeab_system_mail_send(['to' => 'kalle@example.test', 'subject' => 'Fiktiv', 'text' => 'Inget skickas.']);
$blockedSms = jeab_sms_send('0707654321', 'fiktiv');
$blockedEmail = jeab_email_send('kalle@example.test', 'fiktiv text', 'Fiktiv');
$blockedAuto = jeab_auto_quote(
    ['id' => 'trad-fiktiv', 'source' => 'helpscout_import'],
    ['from' => 'kalle@example.test', 'labels' => ['offer' => 'batteri'], 'subject' => 'Fiktiv', 'body' => 'Hej'],
    ['min_confidence' => 1]
);
$blockedRot = jeab_rot_lookup_address('Exempelvägen 1, Exempelby');
$rotAfterLookup = hash('sha256', (string) file_get_contents($rotFile));
$shieldWrote = jeab_shield_write_file($rotFile, "{\"id\":\"andrad\"}\n");
$shieldChanged = hash('sha256', (string) file_get_contents($rotFile)) !== $rotHash;
file_put_contents($rotFile, "{\"id\":\"fiktiv\",\"status\":\"inkomsten\"}\n");
$fortnoxBlocked = false;
try {
    (new FortnoxAPI('Jarfalla'))->get('/customers/9001');
} catch (LogicException $e) {
    $fortnoxBlocked = str_contains($e->getMessage(), 'Fortnox');
}
$blockedQuote = jeab_outbound_graph_send('jarfalla', 'kalle@example.test', 'Fiktiv offert', 'Inget skickas.');
$blockedDraft = jeab_graph_send('jarfalla', 'kalle@example.test', 'Fiktiv', 'Inget skickas.');
$quoteBlocked = ($blockedQuote['ok'] ?? true) === false && str_contains((string) ($blockedQuote['error'] ?? ''), 'Offert');
$draftBlocked = ($blockedDraft['ok'] ?? true) === false;
jeab_kontakt_quiet_leave();
kd(($blockedMail['ok'] ?? true) === false && ($blockedSms['ok'] ?? true) === false && ($blockedEmail['ok'] ?? true) === false, 'grinden stoppar mejl och SMS');
kd(($blockedAuto['action'] ?? '') === 'skip', 'grinden stoppar auto_quote');
kd($fortnoxBlocked && $quoteBlocked && $draftBlocked, 'grinden stoppar Fortnox och offert');
kd(($blockedRot['fastighetsbeteckning'] ?? 'x') === '' && $rotAfterLookup === $rotHash, 'ROT-uppslag under sammanslagning skriver inget');
kd($shieldWrote === true && $shieldChanged && hash('sha256', (string) file_get_contents($rotFile)) === $rotHash, 'shield.php skriver som vanligt och filen är återställd');
kd($sideHits === ['mail' => 0, 'fortnox' => 0, 'sms' => 0, 'auto_quote' => 0, 'quote' => 0], 'grinden når inte Fortnox-, mejl-, SMS- eller offerttransporten');
$openMail = jeab_system_mail_send(['to' => 'kalle@example.test', 'subject' => 'Fiktiv', 'text' => 'Nu är grinden av.']);
$openSms = jeab_sms_send('0707654321', 'fiktiv');
$openQuote = jeab_outbound_graph_send('jarfalla', 'kalle@example.test', 'Fiktiv offert', 'Nu är grinden av.');
$openGraph = jeab_graph_send('jarfalla', 'kalle@example.test', 'Fiktiv', 'Nu är grinden av.');
$openFortnox = (new FortnoxAPI('Jarfalla'))->get('/customers/9001');
kd(($openMail['ok'] ?? false) === true && $sideHits['mail'] === 1, 'systemmejl går till transporten när ingen sammanslagning pågår');
kd(($openSms['ok'] ?? false) === true && $sideHits['sms'] === 1, 'SMS går till transporten när ingen sammanslagning pågår');
kd(($openQuote['ok'] ?? false) === true && $sideHits['quote'] === 1, 'offert går till transporten när ingen sammanslagning pågår');
kd(($openGraph['error'] ?? '') !== 'Mejl är avstängt under sammanslagning och import.', 'vanligt mejl följer den vanliga grinden utanför sammanslagning');
kd((string) ($openFortnox['Customer']['CustomerNumber'] ?? '') === '9001' && $sideHits['fortnox'] === 1, 'Fortnox-anropet når transporten när ingen sammanslagning pågår');
$effects = $GLOBALS['_JEAB_KONTAKT_SIDE_EFFECTS'] ?? [];
kd(is_array($effects) && $effects !== $effectsBefore && in_array('fortnox', $effects, true) && in_array('mail', $effects, true) && in_array('sms', $effects, true) && in_array('auto_quote', $effects, true) && in_array('quote', $effects, true) && in_array('rot', $effects, true), 'grinden antecknar de stoppade anropen');

$hitsBeforeReprocess = $sideHits;
$reprocess = jeab_hs_import_reprocess(['dry' => 0]);
kd(($reprocess['ok'] ?? false) === true && $sideHits['mail'] === $hitsBeforeReprocess['mail'] && $sideHits['fortnox'] === $hitsBeforeReprocess['fortnox'] && $sideHits['sms'] === $hitsBeforeReprocess['sms'] && $sideHits['quote'] === $hitsBeforeReprocess['quote'] && hash('sha256', (string) file_get_contents($rotFile)) === $rotHash, 'importens omkörning skickar inget och rör inte /rot/');
$importCp = [];
$imported = jeab_hs_import_contact_apply([
    'name' => 'Import Exempel',
    'email' => 'import.exempel@example.test',
    'street' => 'Importvägen 4',
    'city' => 'Exempelby',
    'phone' => '070-8880000',
], 'fiktiv-kund', false, $importCp);
kd((string) ($imported['id'] ?? '') !== '' && $sideHits === $hitsBeforeReprocess && hash('sha256', (string) file_get_contents($rotFile)) === $rotHash, 'importerad kontakt skickar ingen offert');

echo "kontakter_dedupe_test klar\n";
