<?php

declare(strict_types=1);

/**
 * Help Scout-kontakter, omgång 5. Bara påhittade namn och adresser.
 */

$tmp = sys_get_temp_dir() . '/jeab-hs5-' . bin2hex(random_bytes(4));
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
    'import_keep_domains' => ['behall.exempel.se'],
    'import_supplier_domain_min' => 6,
], JSON_UNESCAPED_UNICODE));
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/layout.php';
jeab_ordrar_external_live(false);

function r5(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function r5_owner(array $row): string
{
    $owners = is_array($row['owners'] ?? null) ? $row['owners'] : [];
    $first = is_array($owners[0] ?? null) ? $owners[0] : [];
    return trim((string) ($first['name'] ?? ''));
}

function r5_by_email(string $email): ?array
{
    $email = jeab_kontakt_email($email);
    foreach (jeab_kontakter_load() as $row) {
        if (is_array($row) && jeab_kontakt_row_email($row) === $email) {
            return $row;
        }
    }
    return null;
}

/** @param array<string, mixed> $extra */
function r5_store(string $n, string $email, string $name, string $body, string $contactId = '', array $extra = []): string
{
    $tid = 'hsi-' . substr(hash('sha256', 'r5-' . $n), 0, 16);
    $msg = [
        'id' => substr(hash('sha256', 'r5m-' . $n), 0, 16),
        'from' => $email,
        'from_name' => $name,
        'to' => 'kontakt@example.test',
        'subject' => 'Fråga ' . $n,
        'body' => $body,
        'received_at' => '2024-06-02T09:00:00+00:00',
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
        'hs_ids' => ['conversation' => '910' . sprintf('%03d', abs(crc32($n)) % 1000), 'mailbox' => '111', 'customer' => ''],
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
    r5(jeab_hs_import_save_conv($conv) && jeab_hs_import_index_append(jeab_hs_import_index_row($conv)), 'ärende ' . $n . ' skrivs');
    return $tid;
}

function r5_summary_map(array $summary): array
{
    $map = [];
    foreach ($summary as $row) {
        if (!is_array($row)) {
            continue;
        }
        $map[(string) ($row['id'] ?? '')] = (string) ($row['reason'] ?? '');
    }
    return $map;
}

$real = 'Hej, jag vill ha en laddbox till huset på Exempelgatan.';
$store = jeab_store();

$double = jeab_kontakt_normalize_person_name('Anna Maria Lund', 'Lund');
r5($double['name'] === 'Anna Maria Lund' && $double['city'] === '', 'Anna Maria Lund behåller hela namnet när postorten är Lund');
$single = jeab_kontakt_normalize_person_name('Anna Berg Sala', 'Sala');
r5($single['name'] === 'Anna Berg' && $single['city'] === 'Sala', 'Anna Berg Sala skalas fortfarande');
$anna = jeab_hs_import_contact_plan([
    'name' => 'Anna Maria Lund',
    'email' => 'anna.maria.lund@gmail.com',
    'city' => 'Lund',
    'body' => $real,
    'real_customer' => true,
]);
r5($anna['action'] === 'create' && $anna['name'] === 'Anna Maria Lund', 'importen behåller det dubbla förnamnet');

r5(jeab_kontakt_name_is_email('kalle.exempel77@hotmail.com'), 'hotmail-adressen är ett e-postnamn');
r5(jeab_kontakt_name_is_email('kalle@familjenexempel.se'), 'en privat domän är också ett e-postnamn');
r5(!jeab_kontakt_brand_or_form_name('kalle.exempel77@hotmail.com'), 'e-postnamn är inte ett varumärke');
r5(!jeab_kontakt_brand_or_form_name('kalle@familjenexempel.se'), 'privat domän är inte ett varumärke');
r5(!jeab_kontakt_company_name('kalle.exempel77@hotmail.com') && !jeab_kontakt_obvious_company_name('kalle.exempel77@hotmail.com'), 'e-postnamn är inte ett bolag');
r5(jeab_kontakt_brand_or_form_name('Demo Energi Järfälla'), 'Demo Energi Järfälla är fortfarande ett varumärke');
r5(in_array('behall.exempel.se', jeab_hs_import_keep_domains(), true), 'import_keep_domains läses från konfigurationen');

$emailName = jeab_kontakt_blank(['name' => 'kalle.exempel77@hotmail.com', 'email' => 'kalle.exempel77@hotmail.com']);
$privateName = jeab_kontakt_blank(['name' => 'kalle@familjenexempel.se', 'email' => 'kalle@familjenexempel.se']);
$pointedMail = jeab_kontakt_blank(['name' => 'kalle.pekad77@hotmail.com', 'email' => 'kalle.pekad77@hotmail.com']);
$looseVaxel = jeab_kontakt_blank(['name' => 'Växelriktare Inverter', 'email' => 'vaxel@exempelbolag.test']);
$looseMontage = jeab_kontakt_blank(['name' => 'Montage AB', 'email' => 'montage@exempelbolag.test']);
$looseSolar = jeab_kontakt_blank(['name' => 'SolarErbjudanden', 'email' => 'solar@exempelbolag.test']);
$linkedMontage = jeab_kontakt_blank(['name' => 'Montage AB', 'email' => 'montage.kopplad@exempelbolag.test', 'kind' => 'company']);
$annaRow = jeab_kontakt_find_or_create([
    'name' => 'Anna Maria Lund',
    'email' => 'anna.maria.lund@gmail.com',
    'city' => 'Lund',
    'street' => 'Exempelgatan 7',
    'postal_code' => '22222',
], false);
r5(is_array($annaRow) && r5_owner($annaRow) === 'Anna Maria Lund', 'kontakten sparar Anna Maria Lund');
$pre = jeab_kontakt_blank(['name' => 'Bokad Kund', 'email' => 'bokad.kund@gmail.com', 'source' => 'booking']);
$importedJunk = jeab_kontakt_blank(['name' => 'Qwrtzpkxvbn', 'email' => 'skrap.import@gmail.com', 'source' => 'hs-import']);
$importedKept = jeab_kontakt_blank(['name' => 'Importerad Kund', 'email' => 'importerad.kund@gmail.com', 'source' => 'hs-import']);
jeab_kontakter_mutate(function (array &$contacts) use ($emailName, $privateName, $pointedMail, $looseVaxel, $looseMontage, $looseSolar, $linkedMontage, $pre, $importedJunk, $importedKept): void {
    foreach ([$emailName, $privateName, $pointedMail, $looseVaxel, $looseMontage, $looseSolar, $linkedMontage, $pre, $importedJunk, $importedKept] as $row) {
        $contacts[(string) $row['id']] = $row;
    }
});

$pointTid = r5_store('pekad', 'kalle.pekad77@hotmail.com', 'kalle.pekad77@hotmail.com', $real, (string) $pointedMail['id']);
$preTid = r5_store('bokad', 'bokad.kund@gmail.com', 'Qwrtzpkxvbn', $real, (string) $pre['id']);
$junkTid = r5_store('skrap', 'skrap.import@gmail.com', 'Qwrtzpkxvbn', $real, (string) $importedJunk['id']);
$keptTid = r5_store('importerad', 'importerad.kund@gmail.com', 'Qwrtzpkxvbn', $real, (string) $importedKept['id']);

$bookingCustomer = jeab_find_or_create_customer($store, 'Bokad Kund');
$booking = $store->insertBooking([
    'customer_id' => $bookingCustomer['id'],
    'customer_name' => 'Bokad Kund',
    'email' => 'bokad.kund@gmail.com',
    'phone' => '0701000077',
    'address' => 'Exempelgatan 7, Lund',
    'notes' => 'påhittad bokning',
    'assignees' => ['Alias Exempel'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-11-02 09:00:00',
    'ends_at' => '2026-11-02 10:00:00',
    'status' => 'booked',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$store->saveBooking((int) $booking['id'], ['contact_id' => (string) $pre['id']]);
$keptCustomer = jeab_find_or_create_customer($store, 'Importerad Kund');
$keptBooking = $store->insertBooking([
    'customer_id' => $keptCustomer['id'],
    'customer_name' => 'Importerad Kund',
    'email' => 'importerad.kund@gmail.com',
    'phone' => '0701000078',
    'address' => 'Exempelgatan 8, Lund',
    'notes' => 'påhittad bokning',
    'assignees' => ['Alias Exempel'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-11-03 09:00:00',
    'ends_at' => '2026-11-03 10:00:00',
    'status' => 'booked',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$store->saveBooking((int) $keptBooking['id'], ['contact_id' => (string) $importedKept['id']]);
$montageReminder = jeab_create_reminder($store, 'Montage', jeab_today_start(), null, null, [
    'name' => 'Montage AB',
    'customer' => 'Montage AB',
    'email' => 'montage.kopplad@exempelbolag.test',
    'phone' => '0701000079',
    'street' => 'Exempelgatan 9',
    'postal_code' => '22222',
    'city' => 'Lund',
]);
$store->saveReminder((int) $montageReminder['id'], ['contact_id' => (string) $linkedMontage['id']]);

$prune = jeab_kontakter_prune([], $store);
r5(!empty($prune['dry']) && ($prune['removed'] ?? 1) === 0, 'prune är torr som standard');
$pruneMap = r5_summary_map((array) ($prune['summary'] ?? []));
r5(!isset($pruneMap[(string) $emailName['id']]) && !isset($pruneMap[(string) $privateName['id']]), 'e-postnamn föreslås inte som varumärke');
r5(!isset($pruneMap[(string) $pointedMail['id']]), 'ett ärende som pekar på kontakten hindrar prune');
r5(($pruneMap[(string) $looseVaxel['id']] ?? '') === 'company', 'Växelriktare Inverter föreslås när inget pekar');
r5(($pruneMap[(string) $looseMontage['id']] ?? '') === 'company', 'Montage AB föreslås när inget pekar');
r5(($pruneMap[(string) $looseSolar['id']] ?? '') === 'company', 'SolarErbjudanden föreslås när inget pekar');
r5(!isset($pruneMap[(string) $linkedMontage['id']]), 'kopplad Montage AB lämnas kvar');
r5(!isset($pruneMap[(string) $pre['id']]) && !isset($pruneMap[(string) $annaRow['id']]), 'bokad kund och Anna Maria Lund lämnas kvar');
$pruneReasons = [];
foreach ((array) ($prune['contacts'] ?? []) as $candidate) {
    if (is_array($candidate)) {
        $pruneReasons[(string) ($candidate['id'] ?? '')] = (string) ($candidate['reason'] ?? '');
    }
}
r5(!in_array('brand', $pruneReasons, true) || !isset($pruneReasons[(string) $emailName['id']]), 'hotmail-namnet har inte orsaken brand');
$pruneJson = json_encode($prune, JSON_UNESCAPED_UNICODE);
r5(is_string($pruneJson) && !str_contains($pruneJson, '@'), 'prune-loggen har id och orsak, ingen e-post');

$report = jeab_kontakter_link(['dry' => 1], $store);
$companyMap = r5_summary_map((array) ($report['company'] ?? []));
r5(($companyMap[(string) $linkedMontage['id']] ?? '') === 'company?', 'link_report listar kopplat bolag som company?');
r5(!isset($companyMap[(string) $looseVaxel['id']]) && !isset($companyMap[(string) $looseSolar['id']]), 'okopplat bolag ligger inte i company?');
$reportJson = json_encode($report['company'] ?? [], JSON_UNESCAPED_UNICODE);
r5(is_string($reportJson) && !str_contains($reportJson, '@'), 'company?-listan har ingen e-post');

$dry = jeab_hs_import_reprocess([]);
$dryMap = r5_summary_map((array) ($dry['summary'] ?? []));
r5(($dry['contacts_removed'] ?? 0) === 1 && ($dryMap[(string) $importedJunk['id']] ?? '') === 'junk', 'torrkörning tar bara bort importens skräpkontakt');
r5(!isset($dryMap[(string) $pre['id']]) && !isset($dryMap[(string) $importedKept['id']]), 'bokad kontakt och import med bokning tas inte bort');
r5(($dry['contacts_cleared'] ?? 0) >= 1, 'torrkörning räknar länken som ska lossas');
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
r5(is_string($dryJson) && !str_contains($dryJson, '@') && !str_contains($dryJson, 'Qwrtzpkxvbn') && !str_contains($dryJson, 'Bokad'), 'omkörningens torrlogg har bara id och orsak');
r5(r5_by_email('bokad.kund@gmail.com') !== null && r5_by_email('skrap.import@gmail.com') !== null, 'torrkörning skriver inget');

$wet = jeab_hs_import_reprocess(['dry' => 0]);
r5(($wet['contacts_removed'] ?? 0) === 1 && r5_by_email('skrap.import@gmail.com') === null, 'importens skräpkontakt tas bort');
r5(r5_by_email('bokad.kund@gmail.com') !== null && r5_owner((array) r5_by_email('bokad.kund@gmail.com')) === 'Bokad Kund', 'kontakten från bokningen ligger kvar');
r5(r5_by_email('importerad.kund@gmail.com') !== null, 'importkontakt med bokning ligger kvar');
$preConv = jeab_hs_import_read_conv($preTid);
$junkConv = jeab_hs_import_read_conv($junkTid);
$keptConv = jeab_hs_import_read_conv($keptTid);
r5(is_array($preConv) && (string) ($preConv['contact_id'] ?? 'x') === '', 'länken till skräpärendet tas bort från den bokade kontakten');
r5(is_array($junkConv) && (string) ($junkConv['contact_id'] ?? 'x') === '', 'skräpärendets länk är borta');
r5(is_array($keptConv) && (string) ($keptConv['contact_id'] ?? 'x') === '', 'skräpärendets länk tas bort även när kontakten ligger kvar');
r5((string) ($store->getBooking((int) $booking['id'])['contact_id'] ?? '') === (string) $pre['id'], 'bokningen pekar kvar på kontakten');
$wetJson = json_encode($wet, JSON_UNESCAPED_UNICODE);
r5(is_string($wetJson) && !str_contains($wetJson, '@'), 'omkörningens svar har ingen e-post');
unset($pointTid);

$leadIn = jeab_inkorg_ingest([
    'message_id' => '<lead-r5@example.test>',
    'from' => 'Kund Lead <kund.lead@levexempel.se>',
    'from_name' => 'Kund Lead',
    'to' => 'kontakt@example.test',
    'subject' => 'Laddbox',
    'body_text' => $real,
    'source' => 'bridge',
    'preset_labels' => ['type' => 'Ny lead', 'product' => 'elbilsladdare', 'action' => 'Svara', 'priority' => 1],
]);
r5(!empty($leadIn['ok']), 'leaden finns i inkorgen');
$leadPlan = jeab_hs_import_contact_plan([
    'name' => 'Kund Lead',
    'email' => 'kund.lead@levexempel.se',
    'body' => $real,
    'real_customer' => true,
    'subject' => 'Laddbox',
]);
$leadKept = ($leadPlan['action'] === 'create' && ($leadPlan['filter'] ?? '') === 'kept')
    || ($leadPlan['action'] === 'merge' && str_starts_with((string) ($leadPlan['filter'] ?? ''), 'merged into '));
r5($leadKept, 'avsändare med lead behålls även på en leverantörsdomän');

$quoteIn = jeab_inkorg_ingest([
    'message_id' => '<quote-r5@example.test>',
    'from' => 'Kund Offert <kund.offert@levexempel.se>',
    'from_name' => 'Kund Offert',
    'to' => 'kontakt@example.test',
    'subject' => 'Offert',
    'body_text' => $real,
    'source' => 'bridge',
    'preset_labels' => ['type' => 'Support', 'product' => '', 'action' => 'Svara', 'priority' => 1],
]);
r5(!empty($quoteIn['ok']), 'offertärendet finns');
jeab_inkorg_thread_write((string) $quoteIn['thread_id'], static function (array $thread): array {
    $thread['quote_sent'] = ['source' => 'test', 'date' => '2026-01-02'];
    return $thread;
});
$quotePlan = jeab_hs_import_contact_plan([
    'name' => 'Kund Offert',
    'email' => 'kund.offert@levexempel.se',
    'body' => $real,
    'real_customer' => true,
    'subject' => 'Offert',
]);
$quoteKept = $quotePlan['action'] === 'create' || $quotePlan['action'] === 'merge';
r5($quoteKept, 'avsändare med quote_sent behålls');

$reminderOnly = jeab_create_reminder($store, 'Påminnelse', jeab_today_start(), null, null, [
    'name' => 'Kund Paminnelse',
    'customer' => 'Kund Paminnelse',
    'email' => 'kund.paminnelse@levexempel.se',
    'phone' => '0701000081',
    'city' => 'Lund',
]);
r5((int) ($reminderOnly['id'] ?? 0) > 0, 'påminnelsen finns');
$remPlan = jeab_hs_import_contact_plan([
    'name' => 'Kund Paminnelse',
    'email' => 'kund.paminnelse@levexempel.se',
    'body' => $real,
    'real_customer' => true,
]);
r5($remPlan['action'] === 'merge' && (string) ($remPlan['id'] ?? '') === (string) ($reminderOnly['contact_id'] ?? ''), 'avsändare med påminnelse behålls');

$orderReminder = jeab_create_reminder($store, 'Order', jeab_today_start(), null, null, [
    'name' => 'Kund Order',
    'customer' => 'Kund Order',
    'email' => '',
    'phone' => '0701000082',
    'city' => 'Lund',
]);
jeab_purchase_put([
    'reminder_id' => (int) $orderReminder['id'],
    'kind' => 'laddbox',
    'name' => 'Kund Order',
    'email' => 'kund.order@levexempel.se',
    'address' => 'Exempelgatan 4, Lund',
    'company' => 'jarfalla',
    'updated_at' => jeab_now(),
]);
$orderPlan = jeab_hs_import_contact_plan([
    'name' => 'Kund Order',
    'email' => 'kund.order@levexempel.se',
    'body' => $real,
    'real_customer' => true,
]);
r5($orderPlan['action'] === 'create', 'avsändare med order behålls');

$bareSupplier = jeab_hs_import_contact_plan([
    'name' => 'Kund Utan',
    'email' => 'kund.utan@levexempel.se',
    'body' => $real,
    'real_customer' => true,
    'subject' => 'Faktura',
]);
r5($bareSupplier['action'] === 'skip' && ($bareSupplier['filter'] ?? '') === 'supplier', 'leverantörslistan gäller när avsändaren saknar kundspår');

$small = jeab_hs_import_contact_plan([
    'name' => 'Kund Liten',
    'email' => 'kund.liten@annandoman.se',
    'body' => $real,
    'real_customer' => true,
    'subject' => 'Hej',
]);
r5($small['action'] === 'create', 'en domän utanför leverantörslistan behålls');

foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $slot) {
    r5_store('mangd-' . $slot, 'kund.' . $slot . '@mangd.exempel.se', 'Kund Mangd', $real);
    r5_store('behall-' . $slot, 'kund.' . $slot . '@behall.exempel.se', 'Kund Behall', $real);
}
unset($GLOBALS['_JEAB_HS_DOMAIN_STATS'], $GLOBALS['_JEAB_HS_CUSTOMER_TIES']);
r5(jeab_hs_import_domain_is_supplier('kund.a@mangd.exempel.se', 'Fråga mangd-a', false) === true, 'sex ärenden utan lead räknas som leverantör');
$crowd = jeab_hs_import_contact_plan([
    'name' => 'Kund Mangd',
    'email' => 'kund.a@mangd.exempel.se',
    'body' => $real,
    'real_customer' => true,
    'indexed' => true,
    'subject' => 'Fråga mangd-a',
]);
r5($crowd['action'] === 'skip' && ($crowd['filter'] ?? '') === 'supplier', 'filtret släpper inte igenom mängddomänen');
r5(jeab_hs_import_domain_is_supplier('kund.a@behall.exempel.se', 'Fråga behall-a', false) === false, 'import_keep_domains stoppar mängdregeln');
$keepPlan = jeab_hs_import_contact_plan([
    'name' => 'Kund Behall',
    'email' => 'kund.a@behall.exempel.se',
    'body' => $real,
    'real_customer' => true,
    'indexed' => true,
    'subject' => 'Fråga behall-a',
]);
r5($keepPlan['action'] === 'create', 'behåll-domänen blir en kontakt');

$domainReminder = jeab_create_reminder($store, 'Domän', jeab_today_start(), null, null, [
    'name' => 'Kund Mangd',
    'customer' => 'Kund Mangd',
    'email' => 'kund.b@mangd.exempel.se',
    'phone' => '0701000083',
    'city' => 'Lund',
]);
r5((int) ($domainReminder['id'] ?? 0) > 0, 'en avsändare på mängddomänen har en påminnelse');
unset($GLOBALS['_JEAB_HS_CUSTOMER_TIES']);
r5(jeab_hs_import_domain_is_supplier('kund.c@mangd.exempel.se', 'Fråga mangd-c', false) === false, 'mängdregeln gäller inte när någon på domänen har en påminnelse');
$spared = jeab_hs_import_contact_plan([
    'name' => 'Kund Mangd',
    'email' => 'kund.c@mangd.exempel.se',
    'body' => $real,
    'real_customer' => true,
    'indexed' => true,
    'subject' => 'Fråga mangd-c',
]);
r5($spared['action'] === 'create', 'övriga avsändare på domänen behålls');

// Tre bolag av ett litet register är över 5 %. Borttaget är avsiktligt.
$pruneWet = jeab_kontakter_prune(['dry' => 0, 'allow_mass' => 1], $store);
r5(($pruneWet['removed'] ?? 0) >= 3 && r5_by_email('vaxel@exempelbolag.test') === null && r5_by_email('montage@exempelbolag.test') === null && r5_by_email('solar@exempelbolag.test') === null, 'prune tar bort bolag som inget pekar på');
r5(r5_by_email('montage.kopplad@exempelbolag.test') !== null, 'kopplad Montage AB finns kvar efter prune');
r5(r5_by_email('kalle.exempel77@hotmail.com') !== null && r5_by_email('kalle@familjenexempel.se') !== null, 'e-postnamnen finns kvar efter prune');
r5(r5_by_email('anna.maria.lund@gmail.com') !== null && r5_owner((array) r5_by_email('anna.maria.lund@gmail.com')) === 'Anna Maria Lund', 'Anna Maria Lund finns kvar');

$_GET = ['p' => 'kontakter'];
$html = (string) include dirname(__DIR__) . '/src/pages/kontakter.php';
r5(str_contains($html, 'Anna Maria Lund'), 'listan visar Anna Maria Lund');
r5(str_contains($html, 'kalle.exempel77@hotmail.com') && str_contains($html, 'kalle@familjenexempel.se'), 'listan visar e-postnamnen');
r5(str_contains($html, 'Montage AB') && str_contains($html, 'Företag'), 'listan visar det kopplade bolaget');
r5(str_contains($html, 'Bokad Kund'), 'listan visar kontakten från bokningen');
r5(!str_contains($html, 'Växelriktare Inverter') && !str_contains($html, 'SolarErbjudanden'), 'okopplade bolag syns inte efter prune');
$_GET = ['p' => 'kontakter', 'id' => (string) ($annaRow['id'] ?? '')];
$detail = (string) include dirname(__DIR__) . '/src/pages/kontakter.php';
r5(str_contains($detail, 'Anna Maria Lund') && str_contains($detail, 'Lund'), 'kortet visar hela namnet och orten');

echo "OK  round 5\n";
