<?php

declare(strict_types=1);

/**
 * Strikt spärr: inkorg+test-*@example.test, example.com/.org/.net och is_test.
 * Namn, .test, testnummer, example utan inkorg+test- och Sams egna adresser skriver ändå.
 * test_lead.audit är bara läsning: id, domän och flaggor, inte namn, personnummer eller telefon.
 */

$tmp = sys_get_temp_dir() . '/jeab-strict-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'audit-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';
require_once dirname(__DIR__) . '/src/fortnox_leveransdatum.php';
require_once dirname(__DIR__) . '/src/sms.php';
require_once dirname(__DIR__) . '/src/bookings.php';
require_once dirname(__DIR__) . '/src/graph_person.php';
require_once dirname(__DIR__) . '/src/calendar_outlook.php';

function st(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$calls = 0;
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function () use (&$calls): array {
    $calls++;

    return ['Order' => ['DocumentNumber' => '4242', 'CustomerNumber' => '42'], 'Customer' => ['CustomerNumber' => '42', 'Active' => true], 'Customers' => [['CustomerNumber' => '42', 'Active' => true]]];
};
$api = new FortnoxAPI('Jarfalla');

$gmailLead = jeab_test_lead_strict(['email' => 'demo.person@example.invalid']);
$workLead = jeab_test_lead_strict(['email' => 'demo@example.test']);
st($gmailLead['test'] === false && $workLead['test'] === false, 'Sams gmail och jobbmejl är inte strikta');
st(jeab_test_lead_strict(['email' => 'inkorg+test-1@example.test'])['reason'] === 'testadress', 'inkorg+test på example.test är strikt');
st(jeab_test_lead_strict(['email' => 'inkorg+test-1@box.example.test'])['reason'] === 'testadress', 'inkorg+test på underdomän är strikt');
st(jeab_test_lead_strict(['email' => 'ada@example.test'])['test'] === false, 'annan lokaldel på example.test är inte strikt');
st(jeab_test_lead_strict(['email' => 'inkorg+test-1@gmail.com'])['test'] === false, 'inkorg+test på gmail är inte strikt');
st(jeab_test_lead_strict(['email' => 'a@example.com'])['reason'] === 'mejldomän'
    && jeab_test_lead_strict(['email' => 'a@example.org'])['test'] === true
    && jeab_test_lead_strict(['email' => 'a@example.net'])['test'] === true
    && jeab_test_lead_strict(['email' => 'a@example.test'])['test'] === false, 'example.com, .org och .net är strikta, example.test är det inte');
st(jeab_test_lead_strict(['email' => 'a@example.com', 'customer_email' => 'kund@gmail.com'])['test'] === false, 'en riktig adress bland example är inte mejldomän');
st(jeab_test_lead_strict(['epost' => 'a@example.com', 'emails' => ['b@example.net']])['reason'] === 'mejldomän', 'bara example-adresser är mejldomän');
st(jeab_test_lead_strict(['emails' => ['inkorg+test-4@example.test', 'inkorg+test-5@box.example.test']])['reason'] === 'testadress', 'bara inkorg+test-adresser är strikta');
st(jeab_test_lead_strict(['email' => 'kund@gmail.com', 'emails' => ['a@example.com'], 'is_test' => true])['reason'] === 'is_test', 'is_test gäller när en adress är riktig');

$before = $calls;
$order = $api->createKopOrder([
    'kind' => 'laddbox',
    'name' => 'Test Kund',
    'personnummer' => '19800101-0000',
    'fastighetsbeteckning' => 'TEST 1:1',
    'price' => 9990,
    'email' => 'test@example.com',
    'phone' => '0700000000',
]);
st($calls === $before && ($order['orderNumber'] ?? '') === 'TEST', 'fortnox_leveransdatum: example.com skrivs inte');

$before = $calls;
$person = $api->findOrCreateCustomer(['name' => 'Test Person'], 'test@example.org', '0700000000');
st($person === 'TEST' && $calls === $before, 'fortnox_order_export: example.org skrivs inte');

$before = $calls;
$sson = $api->createKopOrder([
    'kind' => 'laddbox',
    'name' => 'Test Testsson',
    'personnummer' => '19800101-0000',
    'fastighetsbeteckning' => 'TEST 1:2',
    'price' => 9990,
    'email' => 'test.testsson@gmail.com',
    'phone' => '0701112233',
]);
st($calls > $before && ($sson['orderNumber'] ?? '') === '4242', 'ordrar_v3: Test Testsson skrivs');

$before = $calls;
$ada = $api->findOrCreateCustomer(['name' => 'Ada Test'], 'ada@example.net', '0702223344');
st($ada === 'TEST' && $calls === $before, 'kontakter_step1: example.net skrivs inte');

$before = $calls;
$domain = $api->createKopOrder([
    'kind' => 'laddbox',
    'name' => 'Kund',
    'personnummer' => '19800101-0000',
    'fastighetsbeteckning' => 'KUND 1:1',
    'price' => 9990,
    'email' => 'kund@example.test',
    'phone' => '0703334455',
]);
st($calls > $before && ($domain['orderNumber'] ?? '') === '4242', 'graph_person: kund@example.test skrivs i Fortnox');

$before = $calls;
$push = jeab_fortnox_leveransdatum_push([
    'customer_name' => 'Test Kund',
    'email' => 'sync-test@example.test',
    'phone' => '0700000000',
    'company' => 'jarfalla',
    'fortnox_order' => '4242',
    'steps' => ['bokad_tid' => '2026-11-16 09:00:00'],
], true);
st(($push['status'] ?? '') !== 'test' && $calls > $before, 'calendar_outlook: sync-test@example.test skrivs i Fortnox');

$before = $calls;
$held = $api->createKopOrder([
    'kind' => 'laddbox',
    'name' => 'Ada',
    'personnummer' => '19800101-0000',
    'fastighetsbeteckning' => 'ADA 1:1',
    'price' => 9990,
    'email' => 'inkorg+test-3@gmail.com',
]);
st($calls > $before && ($held['orderNumber'] ?? '') === '4242', 'inkorg+test på gmail skrivs');
$before = $calls;
$grokHeld = $api->createKopOrder([
    'kind' => 'laddbox',
    'name' => 'Ada',
    'personnummer' => '19800101-0000',
    'fastighetsbeteckning' => 'ADA 1:3',
    'price' => 9990,
    'email' => 'inkorg+test-3@example.test',
]);
st($calls === $before && ($grokHeld['orderNumber'] ?? '') === 'TEST', 'inkorg+test på example.test skrivs inte');
$before = $calls;
$marked = $api->createKopOrder([
    'kind' => 'laddbox',
    'name' => 'Ada',
    'personnummer' => '19800101-0000',
    'fastighetsbeteckning' => 'ADA 1:2',
    'price' => 9990,
    'email' => 'ada@kund.se',
    'is_test' => true,
]);
st($calls === $before && ($marked['orderNumber'] ?? '') === 'TEST', 'is_test skrivs inte');

$smsHits = 0;
$GLOBALS['JEAB_SMS_SEND'] = static function () use (&$smsHits): array {
    $smsHits++;

    return ['ok' => true, 'sent' => 1];
};
$dummy = jeab_sms_send('0700000000', 'Hej', 'jarfalla', null, ['guard_mode' => 'manual', 'name' => 'Test Kund']);
st($smsHits === 1 && ($dummy['ok'] ?? false) === true, 'kontakter_dedupe: 0700000000 skickas');
$plus = jeab_sms_send('+46700000000', 'Hej från Demo Energi Järfälla', 'jarfalla', null, ['guard_mode' => 'manual']);
st($smsHits === 2 && ($plus['ok'] ?? false) === true, 'sms_company_text: +46700000000 skickas');
$namedSms = jeab_sms_send('0707654321', 'Hej', 'jarfalla', null, [
    'guard_mode' => 'manual',
    'name' => 'Test Peak',
    'email' => 'sync-test@example.test',
    'phone' => '0700000000',
]);
st($smsHits === 3 && ($namedSms['ok'] ?? false) === true, 'calendar_pdo_peak: Test Peak och example.test skickas som SMS');
$openSms = jeab_sms_send('0707654321', 'Hej', 'jarfalla', null, [
    'guard_mode' => 'manual',
    'email' => 'ada@box.example.test',
]);
st($smsHits === 4 && ($openSms['ok'] ?? false) === true, 'underdomän utan inkorg+test skickas');
$blockedSms = jeab_sms_send('0707654321', 'Hej', 'jarfalla', null, [
    'guard_mode' => 'manual',
    'email' => 'inkorg+test-z@box.example.test',
]);
st($smsHits === 4 && ($blockedSms['reason'] ?? '') === 'testlead', 'inkorg+test på underdomän skickas inte');

$graphHits = 0;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use (&$graphHits): array {
    $graphHits++;
    if (str_contains($url, '/events')) {
        return ['code' => $method === 'POST' ? 201 : 200, 'body' => ['id' => 'evt-strict-' . $graphHits]];
    }

    return ['code' => 200, 'body' => ['value' => []]];
};
$store = jeab_store();
$peakBefore = $graphHits;
$peak = jeab_create_booking($store, 'Test Peak', ['Elin'], '2026-11-16 09:00:00', '2026-11-16 11:00:00', 'Besiktning', [
    'company' => 'jarfalla',
    'email' => 'sync-test@example.test',
    'phone' => '0700000000',
    'address' => 'Storgatan 1',
    'notes' => 'Behåll den här anteckningen',
], false);
$peakRow = $store->getBooking((int) ($peak['id'] ?? 0));
st($graphHits > $peakBefore && is_array($peakRow) && str_contains((string) ($peakRow['notes'] ?? ''), 'Behåll den här anteckningen'), 'calendar_pdo_peak och booking_notes_save: Test Peak får Graph-händelse och anteckning');
jeab_outlook_event_save((int) $peak['id'], 'Elin', 'saljare4@jarfalla.example.test', 'evt-outlook', 'busy');
st(jeab_outlook_event_id((int) $peak['id'], 'saljare4@jarfalla.example.test') === 'evt-outlook', 'calendar_outlook: sync-test@example.test sparar Graph-id');

$noteBefore = $graphHits;
$note = jeab_create_booking($store, 'Anteckning Test', ['Elin'], '2026-11-17 09:00:00', '2026-11-17 11:00:00', 'Besiktning', [
    'company' => 'jarfalla',
    'email' => 'anteckning@kund.se',
    'phone' => '0700000000',
    'address' => 'Storgatan 2',
    'notes' => 'Anteckningen ska vara kvar',
], false);
$noteRow = $store->getBooking((int) ($note['id'] ?? 0));
st($graphHits > $noteBefore && is_array($noteRow) && str_contains((string) ($noteRow['notes'] ?? ''), 'Anteckningen ska vara kvar'), 'booking_notes_save: Anteckning Test skrivs till Graph');

$strictBefore = $graphHits;
$strictBooking = jeab_create_booking($store, 'Ada', ['Elin'], '2026-11-18 09:00:00', '2026-11-18 11:00:00', 'Besiktning', [
    'company' => 'jarfalla',
    'email' => 'inkorg+test-8@example.test',
    'phone' => '0707654321',
    'address' => 'Storgatan 3',
    'notes' => '',
], false);
st($graphHits === $strictBefore && (int) ($strictBooking['id'] ?? 0) > 0, 'inkorg+test-adress får lokal bokning utan Graph');

$now = date('Y-m-d H:i:s');
$broadReminder = $store->insertReminder([
    'title' => 'Bred',
    'customer_name' => 'Test Kund',
    'email' => 'test@example.test',
    'phone' => '0700000000',
    'company' => 'jarfalla',
    'created_at' => $now,
    'updated_at' => $now,
]);
$strictReminder = $store->insertReminder([
    'title' => 'Strikt',
    'customer_name' => 'Ada',
    'email' => 'inkorg+test-r@a.example.test',
    'phone' => '0707654321',
    'company' => 'jarfalla',
    'created_at' => $now,
    'updated_at' => $now,
]);
$gmailReminder = $store->insertReminder([
    'title' => 'Kund',
    'customer_name' => 'Sam',
    'email' => 'demo.person@example.invalid',
    'phone' => '0701234567',
    'company' => 'jarfalla',
    'created_at' => $now,
    'updated_at' => $now,
]);
jeab_purchase_put([
    'reminder_id' => (int) $broadReminder['id'],
    'customer_name' => 'Test Person',
    'email' => 'kund@example.test',
    'phone' => '0700000000',
    'personnummer' => '198001011234',
]);
jeab_purchase_put([
    'reminder_id' => (int) $strictReminder['id'],
    'customer_name' => 'Ada',
    'email' => 'inkorg+test-2@example.test',
    'phone' => '0701112233',
]);
jeab_kontakter_mutate(static function (array &$contacts): void {
    $contacts['c-broad'] = [
        'id' => 'c-broad',
        'name' => 'Test Kund',
        'email' => 'test@example.test',
        'phone' => '0700000000',
        'owners' => [['name' => 'Test Kund', 'personnummer' => '198001019999', 'email' => 'test@example.test']],
    ];
    $contacts['c-strict'] = [
        'id' => 'c-strict',
        'name' => 'Ada',
        'email' => 'inkorg+test-9@example.test',
        'phone' => '0707654321',
        'owners' => [['name' => 'Ada', 'personnummer' => '198001018888']],
    ];
    $contacts['c-example'] = [
        'id' => 'c-example',
        'name' => 'Exempel',
        'email' => 'a@example.com',
        'phone' => '0703334455',
    ];
    $contacts['c-sam'] = [
        'id' => 'c-sam',
        'name' => 'Sam',
        'email' => 'demo.person@example.invalid',
        'phone' => '0701234567',
    ];
});

$_SERVER['REQUEST_METHOD'] = 'GET';
unset($_SERVER['HTTP_X_WEBHOOK_TOKEN'], $_SERVER['HTTP_AUTHORIZATION']);
$denied = jeab_test_lead_audit_dispatch([]);
st(($denied['ok'] ?? true) === false && ($denied['status'] ?? 0) === 401, 'audit utan POST nekas');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = 'fel';
$wrong = jeab_test_lead_audit_dispatch([]);
st(($wrong['ok'] ?? true) === false && ($wrong['status'] ?? 0) === 401, 'audit med fel token nekas');
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = 'audit-token';
$audit = jeab_test_lead_audit_dispatch([]);
st(($audit['ok'] ?? false) === true, 'audit med token är ok');
$tables = is_array($audit['tables'] ?? null) ? $audit['tables'] : [];
st(isset($tables['contacts'], $tables['purchases'], $tables['reminders'], $tables['bookings']), 'audit räknar kontakter, ordrar, påminnelser och bokningar');
$blob = json_encode($audit, JSON_UNESCAPED_UNICODE);
st(is_string($blob)
    && !str_contains($blob, '@')
    && !str_contains($blob, 'Test')
    && !str_contains($blob, '0700')
    && !str_contains($blob, '19800101')
    && !str_contains($blob, 'personnummer'), 'audit lämnar bara id, inga namn, personnummer eller telefoner');
$strictIds = static function (array $tables, string $table) : array {
    $ids = [];
    foreach (($tables[$table]['strict']['reasons'] ?? []) as $bucket) {
        foreach (($bucket['ids'] ?? []) as $id) {
            $ids[] = (string) $id;
        }
    }
    return $ids;
};
st(in_array('c-strict', $tables['contacts']['strict']['reasons']['testadress']['ids'] ?? [], true)
    && in_array('c-example', $tables['contacts']['strict']['reasons']['mejldomän']['ids'] ?? [], true)
    && !in_array('c-broad', $strictIds($tables, 'contacts'), true)
    && !in_array('c-sam', $strictIds($tables, 'contacts'), true)
    && in_array('c-broad', $tables['contacts']['broad']['reasons']['mejldomän']['ids'] ?? [], true), 'example.com är strikt, example.test är bara bred, Sams gmail är ingen träff');
st(in_array((string) $strictReminder['id'], $tables['purchases']['strict']['reasons']['testadress']['ids'] ?? [], true)
    && in_array((string) $broadReminder['id'], $tables['purchases']['broad']['reasons']['mejldomän']['ids'] ?? [], true)
    && !in_array((string) $broadReminder['id'], $strictIds($tables, 'purchases'), true), 'ordern med example.test räknas bara av den breda matchningen');
st(in_array((string) $strictReminder['id'], $tables['reminders']['strict']['reasons']['testadress']['ids'] ?? [], true)
    && in_array((string) $broadReminder['id'], $tables['reminders']['broad']['reasons']['mejldomän']['ids'] ?? [], true)
    && !in_array((string) $broadReminder['id'], $strictIds($tables, 'reminders'), true)
    && !in_array((string) $gmailReminder['id'], $strictIds($tables, 'reminders'), true), 'påminnelsen med testnamn och Sams gmail är inte strikta träffar');
st(in_array((string) $strictBooking['id'], $tables['bookings']['strict']['reasons']['testadress']['ids'] ?? [], true)
    && in_array((string) $peak['id'], $tables['bookings']['broad']['reasons']['mejldomän']['ids'] ?? [], true)
    && !in_array((string) $peak['id'], $strictIds($tables, 'bookings'), true), 'bokningen Test Peak är bred men inte strikt');
$auditHit = static function (array $tables, string $table, string $side, string $reason, string $id): ?array {
    foreach (($tables[$table][$side]['reasons'][$reason]['hits'] ?? []) as $hit) {
        if (is_array($hit) && (string) ($hit['id'] ?? '') === $id) {
            return $hit;
        }
    }
    return null;
};
$exampleHit = $auditHit($tables, 'contacts', 'strict', 'mejldomän', 'c-example');
$grokHit = $auditHit($tables, 'contacts', 'strict', 'testadress', 'c-strict');
st(is_array($exampleHit)
    && ($exampleHit['domains'] ?? null) === ['example.com']
    && ($exampleHit['has_real_email'] ?? null) === false
    && ($exampleHit['is_test'] ?? null) === false
    && ($exampleHit['has_name'] ?? null) === true
    && is_array($grokHit)
    && ($grokHit['domains'] ?? null) === ['example.test']
    && ($grokHit['has_real_email'] ?? null) === false
    && ($grokHit['is_test'] ?? null) === false
    && ($grokHit['has_name'] ?? null) === true, 'auditträff visar domän, has_real_email, is_test och has_name');
$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
st(str_contains($hook, "=== 'test_lead.audit'") && str_contains($hook, 'jeab_test_lead_audit_dispatch'), 'hooken test_lead.audit finns');

$cc = ['demo.person@example.invalid', 'saljare4@jarfalla.example.test'];
$realLead = [
    'email' => 'ada@kund.se',
    'from' => 'demo@example.test',
    'reply_to' => 'demo.person@example.invalid',
    'person_email' => 'demo@example.test',
];
$fromSam = jeab_test_lead_mail_cc('ada@kund.se', $cc, $realLead);
st($fromSam['block_to'] === false && $fromSam['cc'] === $cc, 'mejl från Sam till en riktig kund behåller kopian');
$note = jeab_test_lead_mail_cc('demo@example.test', ['saljare4@jarfalla.example.test'], $realLead);
st($note['block_to'] === false && $note['cc'] === ['saljare4@jarfalla.example.test'], 'avisering till Sam om en riktig kund går ut med kopia');
$onCc = jeab_test_lead_mail_cc('ada@kund.se', $cc, ['order' => ['email' => 'ada@kund.se', 'from' => 'demo@example.test']]);
st($onCc['block_to'] === false && $onCc['cc'] === $cc, 'Sam på kopia stoppar inte en riktig kund');
$probeReal = jeab_test_lead_send_probe('email', 'demo@example.test', [
    'from' => 'demo.person@example.invalid',
    'reply_to' => 'demo@example.test',
    'cc' => $cc,
    'booking' => ['email' => 'ada@kund.se'],
]);
st($probeReal['test'] === false, 'mottagare och avsändare gör inte en riktig bokning till test');
$ownSam = jeab_test_lead_mail_cc('saljare4@jarfalla.example.test', $cc, ['email' => 'demo.person@example.invalid']);
st($ownSam['block_to'] === false && $ownSam['cc'] === $cc, 'kundens eget mejl demo.person@example.invalid stoppar inte avisering');
$ownGrok = jeab_test_lead_mail_cc('saljare4@jarfalla.example.test', ['saljare4@jarfalla.example.test'], [
    'reminder' => ['email' => 'inkorg+test-x@example.test'],
]);
st($ownGrok['block_to'] === true, 'kundens eget inkorg+test-mejl stoppar avisering');
$toOwn = jeab_test_lead_mail_cc('inkorg+test-x@example.test', ['saljare4@jarfalla.example.test', 'demo@example.test'], [
    'lead' => ['email' => 'inkorg+test-x@example.test'],
]);
st($toOwn['block_to'] === false && $toOwn['cc'] === [], 'testlead får bara mejl till sin egen adress');
$smsOwn = jeab_test_lead_send_probe('sms', '0707654321', [
    'from' => 'demo@example.test',
    'lead' => ['email' => 'demo.person@example.invalid'],
]);
st($smsOwn['test'] === false, 'SMS ser inte Sams gmail som test, och inte avsändaren');

$seenMail = null;
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $args) use (&$seenMail): array {
    $seenMail = $args;

    return ['ok' => true];
};
$sentReal = jeab_outbound_graph_send('jarfalla', 'ada@kund.se', 'Hej', 'Text', false, [], $cc, [
    'from' => 'demo@example.test',
    'person_email' => 'demo@example.test',
    'reply_to' => 'demo.person@example.invalid',
    'email' => 'ada@kund.se',
]);
st(($sentReal['ok'] ?? false) === true && ($seenMail['cc'] ?? null) === $cc, 'utskick från Sam till riktig kund behåller båda kopiorna');
$seenMail = null;
$sentNote = jeab_outbound_graph_send('jarfalla', 'demo@example.test', 'Hej', 'Text', false, [], ['saljare4@jarfalla.example.test'], [
    'from' => 'demo.person@example.invalid',
    'booking' => ['email' => 'ada@kund.se'],
]);
st(($sentNote['ok'] ?? false) === true && ($seenMail['cc'] ?? null) === ['saljare4@jarfalla.example.test'], 'avisering till Sam skickas');
$seenMail = null;
$sentBlocked = jeab_outbound_graph_send('jarfalla', 'ada@kund.se', 'Hej', 'Text', false, [], $cc, [
    'from' => 'demo@example.test',
    'order' => ['email' => 'inkorg+test-x@example.test'],
]);
st($seenMail === null && ($sentBlocked['reason'] ?? '') === 'testlead', 'orderns eget testmejl stoppar mejlet till kunden');

echo "test lead strict: ok\n";
