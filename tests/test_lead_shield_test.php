<?php

declare(strict_types=1);

/**
 * Testlead (inkorg+test-*@example.test) skriver inte till Fortnox, SMS, Outlook eller installatörskopia.
 */

$tmp = sys_get_temp_dir() . '/jeab-shield-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'outgoing_webhook_url' => '',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite'],
], true) . ";\n");
if (!mkdir($tmp . '/rot', 0775, true) && !is_dir($tmp . '/rot')) {
    fwrite(STDERR, "FAIL rot\n");
    exit(1);
}
file_put_contents($tmp . '/rot/fortnox_config.php', "<?php\nreturn ['clientId' => 'test', 'clientSecret' => 'test', 'articles' => []];\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';
require_once dirname(__DIR__) . '/src/fortnox_leveransdatum.php';
date_default_timezone_set('Europe/Stockholm');

function sh(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
    echo "OK  {$message}\n";
}

$calls = 0;
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function () use (&$calls): array {
    $calls++;

    return ['Order' => ['DocumentNumber' => '4242', 'CustomerNumber' => '42'], 'Customer' => ['CustomerNumber' => '42', 'Active' => true], 'Customers' => [['CustomerNumber' => '42', 'Active' => true]]];
};
$GLOBALS['_JEAB_FORTNOX_TEST_LOG'] = [];

$api = new FortnoxAPI('Jarfalla');
$testOrder = $api->createKopOrder(['email' => 'inkorg+test-1@example.test']);
sh(($testOrder['orderNumber'] ?? '') === 'TEST' && ($testOrder['customerNumber'] ?? '') === 'TEST', 'testorder får TEST');
sh(($testOrder['taxReductionError'] ?? 'x') === '' && !empty($testOrder['test']), 'testorder har tomt skattefel');
sh($calls === 0, 'createKopOrder för testlead anropar inte Fortnox');
sh(in_array('test lead, Fortnox skipped', $GLOBALS['_JEAB_FORTNOX_TEST_LOG'], true), 'loggen säger test lead, Fortnox skipped');

$before = $calls;
$real = $api->createKopOrder([
    'kind' => 'laddbox',
    'name' => 'Ada Kund',
    'personnummer' => '19800101-0000',
    'fastighetsbeteckning' => 'ADA 1:1',
    'price' => 9990,
    'email' => 'ada@kund.se',
    'phone' => '0701234567',
]);
sh(($real['orderNumber'] ?? '') === '4242' && $calls > $before, 'vanlig order når Fortnox');

$before = $calls;
$updated = $api->updateKopOrder('TEST', ['email' => 'ada@kund.se', 'name' => 'Ada Kund']);
sh(($updated['orderNumber'] ?? '') === 'TEST' && !empty($updated['updated']) && $calls === $before, 'order TEST uppdateras inte i Fortnox');
$byMail = $api->updateKopOrder('99', ['email' => 'inkorg+test-2@example.test']);
sh(($byMail['orderNumber'] ?? '') === 'TEST' && $calls === $before, 'uppdatering med testmejl hoppar över Fortnox');

$before = $calls;
$api->putDeliveryDate('order', 'TEST', '2026-11-16');
sh($calls === $before, 'leveransdatum på TEST anropar inte Fortnox');
$api->get('/orders/TEST');
sh($calls === $before, 'läsning av dokument TEST anropar inte Fortnox');

$before = $calls;
$customer = $api->findOrCreateCustomer(['name' => 'Ada Kund', 'personnummer' => '19800101-0000'], 'ada@kund.se', '0701234567');
sh($customer === '42' && $calls > $before, 'vanlig kund söks i Fortnox');
$before = $calls;
$named = $api->findOrCreateCustomer(['name' => 'Test Kund'], 'test@example.com', '0700000000');
sh($named === 'TEST' && $calls === $before, 'example.com skapas inte i Fortnox');
$before = $calls;
$skippedCustomer = $api->findOrCreateCustomer(['name' => 'Ada Kund'], 'inkorg+test-4@example.test', '0701234567');
sh($skippedCustomer === 'TEST' && $calls === $before, 'testkund skapas inte i Fortnox');

$ref = new ReflectionMethod(FortnoxAPI::class, 'request');
$ref->setAccessible(true);
$before = $calls;
$ref->invoke($api, 'POST', '/vouchers', ['Voucher' => ['Description' => 'Lon']]);
sh($calls === $before + 1, 'löneunderlag utan testlead skrivs');

$before = $calls;
$push = jeab_fortnox_leveransdatum_push([
    'email' => 'inkorg+test-1@example.test',
    'company' => 'jarfalla',
    'fortnox_order' => '4242',
    'steps' => ['bokad_tid' => '2026-11-16 09:00:00'],
], true);
sh(($push['status'] ?? '') === 'test' && ($push['ok'] ?? false) === true && ($push['skipped'] ?? '') === 'test lead, Fortnox skipped' && $calls === $before, 'leveransdatum för testlead skrivs inte');

$smsHits = 0;
$GLOBALS['JEAB_SMS_SEND'] = static function () use (&$smsHits): array {
    $smsHits++;

    return ['ok' => true, 'sent' => 1];
};
$smsTest = jeab_sms_send('0700000000', 'Hej', 'jarfalla', null, [
    'guard_mode' => 'manual',
    'name' => 'Test Kund',
    'email' => 'test@example.com',
]);
sh($smsHits === 0 && ($smsTest['reason'] ?? '') === 'testlead', 'example.com går inte till SMS-transporten');
$smsLead = jeab_sms_send('0707654321', 'Hej', 'jarfalla', null, [
    'guard_mode' => 'manual',
    'email' => 'inkorg+test-1@example.test',
    'name' => 'Ada',
]);
sh($smsHits === 0 && ($smsLead['reason'] ?? '') === 'testlead', 'SMS med inkorg+test-adress skickas inte');
$smsReal = jeab_sms_send('0707654321', 'Hej', 'jarfalla', null, ['guard_mode' => 'manual', 'email' => 'ada@kund.se']);
sh($smsHits === 1 && ($smsReal['ok'] ?? false) === true, 'SMS till vanligt nummer går till transporten');
$smsGmail = jeab_sms_send('0707654321', 'Hej', 'jarfalla', null, [
    'guard_mode' => 'manual',
    'email' => 'demo.person@example.invalid',
]);
sh($smsHits === 2 && ($smsGmail['ok'] ?? false) === true, 'Sams gmail som kundmejl skickas');

$graphHits = 0;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null) use (&$graphHits): array {
    unset($body);
    $graphHits++;
    if (str_contains($url, '/events')) {
        return ['code' => $method === 'POST' ? 201 : 200, 'body' => ['id' => 'evt-real']];
    }

    return ['code' => 200, 'body' => ['value' => []]];
};

$store = jeab_store();
$testBooking = jeab_create_booking($store, 'Ada', ['Elin'], '2026-11-16 09:00:00', '2026-11-16 10:00:00', 'laddbox', [
    'company' => 'jarfalla',
    'email' => 'inkorg+test-3@example.test',
    'phone' => '0701234567',
], false);
$fresh = $store->getBooking((int) $testBooking['id']);
sh(is_array($fresh) && str_starts_with((string) ($fresh['notes'] ?? ''), 'TEST'), 'bokningen sparas lokalt märkt TEST');
sh($graphHits === 0 && jeab_outlook_events_for_booking((int) $testBooking['id']) === [], 'testbokning skapar ingen Outlook-händelse');
sh(jeab_gp_upsert_event('saljare4@jarfalla.example.test', $fresh) === true && $graphHits === 0, 'upsert för testlead anropar inte Graph');
jeab_outlook_event_save((int) $testBooking['id'], 'Elin', 'saljare4@jarfalla.example.test', 'evt-should-not', 'busy');
sh(jeab_outlook_events_for_booking((int) $testBooking['id']) === [], 'event-save för testlead skriver inget id');

$realBooking = jeab_create_booking($store, 'Ada Kund', ['Elin'], '2026-11-17 09:00:00', '2026-11-17 10:00:00', 'laddbox', [
    'company' => 'jarfalla',
    'email' => 'ada@kund.se',
    'phone' => '0701234567',
], false);
sh($graphHits > 0 && jeab_outlook_events_for_booking((int) $realBooking['id']) !== [], 'vanlig bokning skapar en Outlook-händelse');

$seen = null;
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $args) use (&$seen): array {
    $seen = $args;

    return ['ok' => true];
};
$sent = jeab_outbound_graph_send('jarfalla', 'inkorg+test-9@example.test', 'Hej', 'Text', false, [], ['saljare4@jarfalla.example.test'], [
    'email' => 'inkorg+test-9@example.test',
]);
sh(($sent['ok'] ?? false) === true && ($seen['cc'] ?? null) === [] && !empty($seen['opts']['strip_cc']), 'testadress får mejlet utan installatörskopia');
$seen = null;
$kept = jeab_outbound_graph_send('jarfalla', 'ada@kund.se', 'Hej', 'Text', false, [], ['saljare4@jarfalla.example.test']);
sh(($kept['ok'] ?? false) === true && ($seen['cc'] ?? null) === ['saljare4@jarfalla.example.test'], 'vanlig kund behåller kopia');
$seen = null;
$exampleKept = jeab_outbound_graph_send('jarfalla', 'test@example.com', 'Hej', 'Text', false, [], ['saljare4@jarfalla.example.test'], [
    'email' => 'test@example.com',
    'name' => 'Test Testsson',
    'phone' => '0700000000',
]);
sh(($exampleKept['ok'] ?? false) === true && ($seen['cc'] ?? null) === [] && !empty($seen['opts']['strip_cc']), 'example.com får mejlet utan kopia');
$seen = null;
$gmailKept = jeab_outbound_graph_send('jarfalla', 'demo.person@example.invalid', 'Hej', 'Text', false, [], ['saljare4@jarfalla.example.test'], [
    'email' => 'demo.person@example.invalid',
]);
sh(($gmailKept['ok'] ?? false) === true && ($seen['cc'] ?? null) === ['saljare4@jarfalla.example.test'], 'Sams gmail som kundmejl behåller kopia');
$seen = null;
$blocked = jeab_outbound_graph_send('jarfalla', 'saljare4@jarfalla.example.test', 'Hej', 'Text', false, [], ['saljare4@jarfalla.example.test'], [
    'email' => 'inkorg+test-9@example.test',
]);
sh($seen === null && ($blocked['reason'] ?? '') === 'testlead', 'testlead skickas inte till installatören');

echo "test lead shield: ok\n";
