<?php

declare(strict_types=1);

/**
 * QA efter #646/#651/#655. Påhittade adresser. Inga personnummer.
 * Offerten skickas bara automatiskt. Vunnen ligger under Visa mer, kopierar eller vägrar,
 * kundkortet läser mejlet, flaggan är tre minuter.
 */

$tmp = sys_get_temp_dir() . '/jeab-qa-four-' . bin2hex(random_bytes(4));
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function q4(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
$root = dirname(__DIR__);

$withMail = [
    'id' => 11,
    'title' => 'Batteri',
    'customer_name' => 'QA Offert',
    'email' => 'offert@example.test',
    'company' => 'jarfalla',
    'tags' => [],
];
$mailHtml = jeab_reminder_row($withMail, true);
$headAt = strpos($mailHtml, 'item-tags-head');
$panelAt = strpos($mailHtml, 'item-more-panel');
q4($headAt !== false && $panelAt !== false && $headAt < $panelAt, 'Visa mer-panelen ligger under kortets rad');
q4(!str_contains($mailHtml, 'Skicka offert') && !str_contains($mailHtml, 'reminder.send_quote') && !str_contains($mailHtml, 'data-send-quote'), 'kortet har ingen manuell offertknapp');
$headSlice = substr($mailHtml, $headAt, $panelAt - $headAt);
q4(!str_contains($headSlice, '#vunnen'), 'kortets rad har ingen Vunnen');
q4(str_contains(substr($mailHtml, $panelAt), 'name="tag" value="vunnen"') && str_contains(substr($mailHtml, $panelAt), 'boka tid i kalendern'), 'Vunnen ligger under Visa mer, tillsammans med kalenderknappen');
q4(strpos($mailHtml, 'boka tid i kalendern') < strpos($mailHtml, 'name="tag" value="vunnen"'), 'kalenderknappen ligger före taggarna');
// Kortets rad skriver inte ut mejladressen. Kontaktpanelen under Visa mer får visa kopian (#701: saknad länk lämnar kundkopian).
q4(!str_contains($headSlice, 'offert@example.test'), 'kortets rad skriver inte ut mejladressen');

$noMail = ['id' => 12, 'title' => 'Utan mejl', 'company' => 'jarfalla', 'tags' => []];
$noHtml = jeab_reminder_row($noMail, true);
q4(!str_contains($noHtml, 'Skicka offert') && !str_contains($noHtml, 'Saknar mejl'), 'utan mejl finns ingen offertknapp');

$inkorg = (string) file_get_contents($root . '/src/pages/inkorg.php');
$sendLine = '';
foreach (explode("\n", $inkorg) as $line) {
    if (str_contains($line, '>Skicka offert<')) {
        $sendLine = $line;
    }
}
q4($sendLine !== '' && !str_contains($sendLine, 'awaiting_quote'), 'menyn visar Skicka offert utan att kräva väntande offert');
q4(str_contains($inkorg, 'disabled>Saknar mejl'), 'menyn visar Saknar mejl när Till saknar adress');
q4(str_contains($inkorg, 'data-send-quote="1"'), 'menyns offert låser fortfarande knappen');

$bare = $store->insertReminder([
    'title' => 'Hej',
    'company' => 'jarfalla',
    'customer_name' => 'QA Tom',
    'email' => 'tom@example.test',
    'tags' => ['vunnen'],
]);
$bareId = (int) $bare['id'];
$refused = false;
$refusedMsg = '';
try {
    jeab_ordrar_vunnen_from_tag($store, $bare);
} catch (InvalidArgumentException $e) {
    $refused = true;
    $refusedMsg = $e->getMessage();
}
q4($refused && str_starts_with($refusedMsg, 'Ordern skapades inte') && str_contains($refusedMsg, 'adress') && str_contains($refusedMsg, 'produkt'), 'Vunnen utan adress och produkt skapar ingen order');
q4(!str_contains($refusedMsg, '@'), 'vägran nämner ingen mejladress');
q4(jeab_purchase_get($bareId) === null, 'ingen tom order blev kvar');
$bareAfter = jeab_get_reminder($store, $bareId);
q4(is_array($bareAfter) && !in_array('vunnen', jeab_parse_tags($bareAfter['tags'] ?? []), true), 'vägran tar bort vunnen-taggen');

$contact = jeab_kontakt_find_or_create([
    'name' => 'Kim Vunnen',
    'email' => 'kim.vunnen@example.test',
    'phone' => '0701000091',
    'address' => 'Vunnengatan 4, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
q4(is_array($contact), 'testkontakten finns');
$won = $store->insertReminder([
    'title' => 'Batteri',
    'company' => 'malmo',
    'assignee' => 'Kim',
    'tags' => ['vunnen'],
    'note' => 'batteri',
]);
$won = $store->saveReminder((int) $won['id'], ['contact_id' => (string) $contact['id']]);
$wonId = (int) $won['id'];
$first = jeab_ordrar_vunnen_purchase($store, $wonId, $won);
$second = jeab_ordrar_vunnen_purchase($store, $wonId, $won);
q4((int) ($first['reminder_id'] ?? 0) === $wonId && (int) ($second['reminder_id'] ?? 0) === $wonId, 'andra klicket lämnar samma order');
q4(count(jeab_purchase_all()) === 1, 'dubbelklick skapar en order');
q4((string) ($first['kind'] ?? '') === 'batteri', 'produkten kopieras');
q4(trim((string) ($first['address'] ?? '')) === '', 'ordern lagrar inte adresskopian när kontakten är källan');
q4((string) ($first['contact_id'] ?? '') === (string) $contact['id'], 'kontakten kopieras');
q4(jeab_ordrar_name($first) === 'Kim Vunnen', 'orderlistan visar kontaktens namn');
q4(str_contains(jeab_ordrar_board_place($first), 'Vunnengatan'), 'orderlistan visar adressen');
q4((int) ($first['booking_id'] ?? 0) === 0 && (int) ($second['booking_id'] ?? 0) === 0, 'Vunnen skapar ingen bokning');
$shown = jeab_ordrar_proposed_slot($store, $first);
q4(is_array($shown) && ($shown['who'][0] ?? '') === 'Kim', 'Bekräfta visar Kims slot');
$booked = 0;
foreach (jeab_calendar_log_read()['entries'] as $entry) {
    if (is_array($entry) && (string) ($entry['type'] ?? '') === 'booked') {
        $booked++;
    }
}
q4($booked === 0, 'öppning skriver ingen kalenderlogg');
$confirmed = jeab_ordrar_create_booking($store, ['id' => $wonId]);
$again = jeab_ordrar_create_booking($store, ['id' => $wonId]);
q4((int) ($confirmed['booking_id'] ?? 0) > 0 && (int) ($again['booking_id'] ?? 0) === (int) ($confirmed['booking_id'] ?? 0), 'Bekräfta bokar en gång och behåller kopplingen');
q4(is_array(jeab_get_reminder($store, $wonId)), 'påminnelsen finns kvar efter bokning');
$booked = 0;
foreach (jeab_calendar_log_read()['entries'] as $entry) {
    if (is_array($entry) && (string) ($entry['type'] ?? '') === 'booked') {
        $booked++;
    }
}
q4($booked === 1, 'bokningen skriver en kalenderlogg');

$jar = $store->insertReminder([
    'title' => 'Laddbox',
    'company' => 'jarfalla',
    'customer_name' => 'Elin Kund',
    'email' => 'elin.kund@example.test',
    'phone' => '0701000092',
    'address' => 'Testgatan 1',
    'notes' => 'laddbox',
    'tags' => ['vunnen'],
]);
$jarPurchase = jeab_ordrar_vunnen_purchase($store, (int) $jar['id'], $jar);
q4((int) ($jarPurchase['booking_id'] ?? 0) === 0, 'Järfälla vunnen autobokar inte');

$empty = $store->insertReminder([
    'title' => 'Batteri',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
    'note' => 'batteri',
]);
$empty = $store->saveReminder((int) $empty['id'], ['contact_id' => (string) $contact['id']]);
jeab_purchase_put([
    'reminder_id' => (int) $empty['id'],
    'kind' => '',
    'name' => '',
    'email' => '',
    'phone' => '',
    'address' => '',
    'company' => 'jarfalla',
]);
$filled = jeab_ordrar_vunnen_purchase($store, (int) $empty['id'], $empty);
q4((string) ($filled['kind'] ?? '') === 'batteri' && trim((string) ($filled['address'] ?? '')) === '', 'befintlig tom order fyller produkt och lagrar inte adresskopian');
q4(str_contains(jeab_ordrar_board_place($filled), 'Vunnengatan'), 'ifyllnaden visas från kontakten');
q4(jeab_purchase_get((int) $empty['id']) !== null && count(array_filter(jeab_purchase_all(), static fn ($row): bool => is_array($row) && (int) ($row['reminder_id'] ?? 0) === (int) $empty['id'])) === 1, 'ifyllnaden lämnar en rad');

$multi = $store->insertReminder([
    'title' => 'batteri+sol',
    'company' => 'malmo',
    'assignee' => 'Kim',
    'customer_name' => 'Kim Blandad',
    'email' => 'blandad@example.test',
    'phone' => '0701000095',
    'address' => 'Blandgatan 3',
    'tags' => ['vunnen'],
]);
$multiPurchase = jeab_ordrar_vunnen_purchase($store, (int) $multi['id'], $multi);
q4((string) ($multiPurchase['kind'] ?? '') === 'batteri', 'flera produkter tar den första');
q4((int) ($multiPurchase['booking_id'] ?? 0) === 0, 'flera produkter bokar inte');
$solFirst = $store->insertReminder([
    'title' => 'sol+batteri',
    'company' => 'jarfalla',
    'customer_name' => 'Sol Först',
    'email' => 'solforst@example.test',
    'address' => 'Solgatan 1',
    'tags' => ['vunnen'],
]);
$solPurchase = jeab_ordrar_vunnen_purchase($store, (int) $solFirst['id'], $solFirst);
q4((string) ($solPurchase['kind'] ?? '') === 'sol', 'sol+batteri tar sol som primär');
$none = $store->insertReminder([
    'title' => 'Bara adress',
    'company' => 'jarfalla',
    'customer_name' => 'Utan Produkt',
    'email' => 'utan@example.test',
    'address' => 'Tomgatan 9',
    'tags' => ['vunnen'],
]);
$noneRefused = false;
try {
    jeab_ordrar_vunnen_from_tag($store, $none);
} catch (InvalidArgumentException $e) {
    $noneRefused = str_contains($e->getMessage(), 'produkt');
}
q4($noneRefused && jeab_purchase_get((int) $none['id']) === null, 'utan produkt skapas ingen order');

$thread = [
    'id' => 'thr-qa-kort',
    'contact_id' => '',
    'messages' => [[
        'from' => 'till.kund@example.test',
        'received_at' => '2026-10-22 10:00:00',
        'body' => 'Hej',
    ]],
];
$box = jeab_inkorg_contact_box($thread, null, ['email' => '', 'phone' => '']);
q4($box['emails'] === ['till.kund@example.test'], 'kundkortet läser samma mejl som Till');

$store->insertReminder([
    'title' => 'Batteri',
    'company' => 'jarfalla',
    'email' => 'paminnelse@example.test',
    'thread_id' => 'thr-qa-pam',
    'tags' => [],
]);
$fromReminder = jeab_inkorg_contact_box(['id' => 'thr-qa-pam', 'contact_id' => '', 'messages' => []], null, ['email' => '']);
q4($fromReminder['emails'] === ['paminnelse@example.test'], 'kundkortet läser påminnelsens mejl');

$store->insertReminder([
    'title' => 'Nytt lead',
    'company' => 'jarfalla',
    'email' => 'lead@example.test',
    'phone' => '0701000093',
    'address' => 'Leadgatan 8, 111 22 Teststad',
    'ort' => 'Teststad',
    'thread_id' => 'thr-qa-lead',
    'tags' => [],
]);
$leadThread = ['id' => 'thr-qa-lead', 'contact_id' => '', 'messages' => []];
$leadBox = jeab_inkorg_contact_box($leadThread, null, ['email' => '', 'phone' => '']);
$leadPlace = jeab_inkorg_panel_place($leadThread, null);
q4($leadBox['emails'] === ['lead@example.test'], 'nytt lead visar mejlet från påminnelsen');
q4($leadBox['phone'] !== '', 'nytt lead visar telefonen från påminnelsen');
q4(str_contains((string) $leadPlace['address'], 'Leadgatan'), 'nytt lead visar adressen från påminnelsen');

$via = $store->insertReminder([
    'title' => 'Lead via kontakt',
    'company' => 'jarfalla',
    'thread_id' => 'thr-qa-via',
    'tags' => [],
]);
$store->saveReminder((int) $via['id'], ['contact_id' => (string) $contact['id']]);
unset($GLOBALS['_JEAB_INKORG_THREAD_REM']);
$viaThread = ['id' => 'thr-qa-via', 'contact_id' => '', 'messages' => []];
$viaBox = jeab_inkorg_contact_box($viaThread, null, ['email' => '', 'phone' => '']);
$viaPlace = jeab_inkorg_panel_place($viaThread, null);
q4($viaBox['emails'] === ['kim.vunnen@example.test'], 'kortet läser mejlet från den länkade kontakten');
q4($viaBox['phone'] !== '', 'kortet läser telefonen från den länkade kontakten');
q4(str_contains((string) $viaPlace['address'], 'Vunnengatan'), 'kortet läser adressen från den länkade kontakten');

$store->insertReminder([
    'title' => 'Lead i anteckning',
    'company' => 'jarfalla',
    'email' => 'not@example.test',
    'phone' => '0701000094',
    'address' => 'Notgatan 2',
    'ort' => 'Teststad',
    'note' => 'mail: https://flowstack.example.test/index.php?p=inkorg&id=thr-qa-note',
    'tags' => [],
]);
unset($GLOBALS['_JEAB_INKORG_THREAD_REM']);
$noteThread = ['id' => 'thr-qa-note', 'contact_id' => 'inte-en-kontakt', 'messages' => []];
$noteBox = jeab_inkorg_contact_box($noteThread, null, ['email' => '', 'phone' => '']);
$notePlace = jeab_inkorg_panel_place($noteThread, null);
q4($noteBox['emails'] === ['not@example.test'] && $noteBox['phone'] !== '', 'anteckningens ärende ger mejl och telefon');
q4(str_contains((string) $notePlace['address'], 'Notgatan'), 'anteckningens ärende ger adressen');

$linked = jeab_inkorg_contact_box([
    'id' => 'thr-qa-linked',
    'contact_id' => (string) $contact['id'],
    'messages' => [[
        'from' => 'annan@example.test',
        'received_at' => '2026-10-22 11:00:00',
        'body' => 'Hej',
    ]],
], null, ['email' => '']);
q4($linked['emails'] === ['kim.vunnen@example.test'], 'kopplad kontakt vinner över Till');

$index = (string) file_get_contents($root . '/index.php');
$remindersSrc = (string) file_get_contents($root . '/src/reminders.php');
q4(!str_contains($index, 'reminder.send_quote') && !str_contains($remindersSrc, 'function jeab_reminder_send_quote_action'), 'kortets offertåtgärd är borta');

q4(jeab_deploy_flag_ttl() === 180, 'underhållsflaggan är färsk i tre minuter');

echo "OK  fyra QA-buggar\n";
