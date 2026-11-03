<?php

declare(strict_types=1);

/**
 * Betald Wix-butiksorder i inkorgen blir Order, en vunnen påminnelse och stänger
 * öppna leadtrådar för samma kund och produkt. Inget kundutskick. Bara påhittade kunder.
 */

$tmp = sys_get_temp_dir() . '/jeab-webshop-inbox-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
$GLOBALS['_JEAB_LOG_PDO'] = new PDO('sqlite::memory:');
$GLOBALS['_JEAB_LOG_PDO']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
jeab_log_pdo();
jeab_log_flag_set($GLOBALS['_JEAB_LOG_PDO'], 'webshop_orders');
jeab_ordrar_external_live(false);
date_default_timezone_set('Europe/Stockholm');
jeab_inkorg_helpscout_companies_save([]);

$GLOBALS['JEAB_MAILS'] = 0;
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (): array {
    $GLOBALS['JEAB_MAILS']++;
    return ['ok' => true, 'path' => 'mock', 'id' => 'm'];
};
$GLOBALS['JEAB_FORTNOX_FINDS'] = 0;
$GLOBALS['JEAB_FORTNOX_WRITES'] = 0;
$GLOBALS['_JEAB_WEBSHOP_FORTNOX_FIND'] = static function (array $ws): array {
    unset($ws);
    $GLOBALS['JEAB_FORTNOX_FINDS']++;
    return [
        ['order' => '4401', 'customer' => '9', 'match' => 'wix_number'],
    ];
};
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, mixed $body = null): array {
    unset($path, $body);
    if (strtoupper($method) !== 'GET') {
        $GLOBALS['JEAB_FORTNOX_WRITES']++;
    }
    return ['Orders' => [], 'Order' => ['DocumentNumber' => '']];
};

function wo(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function wo_body(string $num, string $product, string $name, string $email, string $phone, string $street, string $zip, string $city, string $pay = 'Betalt via Klarna'): string
{
    return "Ordernummer: {$num}\n{$pay}\n1 × {$product}\nTotalt: 8 490 kr\nNamn: {$name}\nE-post: {$email}\nTelefon: {$phone}\nAdress: {$street}\nPostnummer: {$zip}\nOrt: {$city}\n";
}

function wo_msg(string $from, string $subject, string $body, string $company = 'jarfalla'): array
{
    $box = $company === 'malmo' ? 'kontakt@malmo.example.test' : 'kontakt@jarfalla.example.test';
    return [
        'from' => $from,
        'from_name' => 'Butik',
        'to' => $box,
        'subject' => $subject,
        'body' => $body,
        'source' => 'graph-' . $company,
        'graph_mailbox' => $box,
    ];
}

function wo_ingest(string $id, string $from, string $subject, string $body, string $company = 'jarfalla', ?array $preset = null, string $received = '2026-10-18T09:00:00+02:00'): array
{
    $box = $company === 'malmo' ? 'kontakt@malmo.example.test' : 'kontakt@jarfalla.example.test';
    $row = [
        'message_id' => $id . '@wo.test',
        'from' => $from,
        'from_name' => 'Butik',
        'to' => $box,
        'subject' => $subject,
        'body_text' => $body,
        'source' => 'graph-' . $company,
        'graph_mailbox' => $box,
        'received_at' => $received,
        'test' => true,
    ];
    if ($preset !== null) {
        $row['preset_labels'] = $preset;
    }
    return jeab_inkorg_ingest($row);
}

function wo_lead(string $id, string $from, string $name, string $body, string $offer, string $company = 'jarfalla'): array
{
    $box = $company === 'malmo' ? 'kontakt@malmo.example.test' : 'kontakt@jarfalla.example.test';
    return jeab_inkorg_ingest([
        'message_id' => $id . '@wo.test',
        'from' => $from,
        'from_name' => $name,
        'to' => $box,
        'subject' => 'Offert ' . $offer,
        'body_text' => $body,
        'source' => 'graph-' . $company,
        'graph_mailbox' => $box,
        'preset_labels' => [
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 100,
            'intent' => 'kop', 'offer' => $offer, 'product' => $offer,
        ],
        'test' => true,
    ]);
}

function wo_status(string $tid): string
{
    return (string) (jeab_inkorg_thread_meta($tid)['status'] ?? '');
}

function wo_notes(string $tid): string
{
    $bits = [];
    foreach (jeab_inkorg_threads() as $t) {
        if ((string) ($t['id'] ?? '') !== $tid) {
            continue;
        }
        foreach ((array) ($t['drafts'] ?? []) as $d) {
            if (is_array($d) && ($d['kind'] ?? '') === 'note') {
                $bits[] = (string) ($d['body'] ?? '');
            }
        }
    }
    return implode("\n", $bits);
}

$store = jeab_store();
$info = ['type' => 'Information', 'action' => 'arkivera', 'priority' => 4, 'confidence' => 90, 'source' => 'llm'];
$mira = wo_body('10045', 'Easee Home laddbox', 'Mira Exempel', 'mira.exempel@example.com', '070-111 22 33', 'Exempelvägen 12', '177 31', 'Järfälla');

$rule = jeab_webshop_builtin_rule();
wo(is_array($rule) && !empty($rule['builtin']) && !empty($rule['enabled']) && ($rule['type'] ?? '') === 'Order', 'inbyggd regel finns och är på');
wo(in_array('Betald webbshoporder (Wix) är Order', array_column(jeab_inkorg_rules_ordered(), 'name'), true), 'regeln syns bland reglerna');
wo(str_contains(implode("\n", jeab_auto_rule_lines()), 'inbox.webshop_orders'), 'automatikraden nämner hooken');
wo(str_contains(jeab_inkorg_seed_settings()['intent'], 'typen Order'), 'instruktionen nämner typen Order');

$paid = jeab_inkorg_classify(wo_msg('info@stores-emails.com', 'Ny order', $mira));
wo($paid['type'] === 'Order' && ($paid['rule_id'] ?? '') === 'builtin-webshop-order', 'svensk betald butiksorder blir Order');
$en = "Order number: 10088\nPaid via card\n1 x Easee Home laddbox\nTotal: 8490.00\nName: Mira Exempel\nEmail: mira.en@example.com\nPhone: 070-111 22 44\nAddress: Exempelvägen 12, 177 31 Järfälla\n";
wo(jeab_inkorg_classify(wo_msg('orders@wix.com', 'Order paid', $en))['type'] === 'Order', 'engelsk betald butiksorder blir Order');
$unpaid = wo_body('10046', 'Easee Home laddbox', 'Mira Exempel', 'mira.exempel@example.com', '070-111 22 33', 'Exempelvägen 12', '177 31', 'Järfälla', 'Ej betalt');
wo(jeab_inkorg_classify(wo_msg('info@stores-emails.com', 'Order', $unpaid))['type'] === 'Information', 'Ej betalt förblir Information');
$aban = "Ordernummer: 10047\nÖvergiven varukorg\n1 × Easee Home laddbox\n";
wo(jeab_inkorg_classify(wo_msg('info@stores-emails.com', 'Varukorg', $aban))['type'] === 'Information', 'övergiven varukorg förblir Information');
$cancel = wo_body('10048', 'Easee Home laddbox', 'Mira Exempel', 'mira.exempel@example.com', '070-111 22 33', 'Exempelvägen 12', '177 31', 'Järfälla', "Betalt\nAvbruten");
wo(jeab_inkorg_classify(wo_msg('info@stores-emails.com', 'Order', $cancel))['type'] === 'Information', 'avbruten order förblir Information');
$obetald = "Ordernummer: 10049\nObetald\n1 × Easee Home laddbox\n";
wo(jeab_inkorg_classify(wo_msg('info@stores-emails.com', 'Order', $obetald))['type'] === 'Information', 'Obetald är inte betald');
wo(jeab_inkorg_classify(wo_msg('info@stores-emails.com', 'Order', "Ordernummer: 10050\nUnpaid\n"))['type'] === 'Information', 'Unpaid är inte paid');

jeab_inkorg_rule_toggle('builtin-webshop-order');
wo(jeab_inkorg_classify(wo_msg('info@stores-emails.com', 'Ny order', $mira))['type'] === 'Oklassad', 'avstängd regel klassar inte ordern');
jeab_inkorg_rule_toggle('builtin-webshop-order');
wo(jeab_inkorg_classify(wo_msg('info@stores-emails.com', 'Ny order', $mira))['type'] === 'Order', 'påslagen regel klassar Order igen');

$saved = jeab_inkorg_rule_save([
    'id' => 'builtin-webshop-order',
    'name' => 'Betald webbshoporder (Wix) är Order',
    'match' => 'all',
    'conditions' => $rule['conditions'],
    'type' => 'Order',
    'confidence' => 100,
]);
wo(!empty($saved['builtin']), 'redigering behåller att regeln är inbyggd');

$lead = wo_lead('lead-mira', 'mira.exempel@example.com', 'Mira Exempel', "Hej, jag vill ha en elbilsladdare.\nNamn: Mira Exempel\nE-post: mira.exempel@example.com\nTelefon: 070-111 22 33\nAdress: Exempelvägen 12, 177 31 Järfälla\n", 'laddbox');
$other = wo_lead('lead-bat', 'mira.exempel@example.com', 'Mira Exempel', "Hej, jag vill ha ett batteri.\n", 'batteri');
$phoneLead = wo_lead('lead-phone', 'noel.telefon@example.com', 'Noel Telefon', "Hej, laddbox tack.\nTelefon: 070-111 22 33\nAdress: Annangatan 4, 222 21 Lund\n", 'laddbox');
$addrLead = wo_lead('lead-addr', 'nagon.annan@example.com', 'Nagon Annan', "Hej, laddbox.\nAdress: Exempelvägen 12, 177 31 Järfälla\nTelefon: 070-999 88 77\n", 'laddbox');
$stranger = wo_lead('lead-stranger', 'frida.exempel@example.com', 'Frida Exempel', "Hej, laddbox.\nTelefon: 070-222 33 44\nAdress: Björkvägen 2, 176 70 Järfälla\n", 'laddbox');

$old = wo_ingest('old-mira', 'info@stores-emails.com', 'Kvitto', $mira, 'jarfalla', $info);
wo(($old['labels']['type'] ?? '') === 'Information', 'gammal order ligger kvar som Information tills hooken');
$dry = jeab_inkorg_hook(['action' => 'inbox.webshop_orders', 'order' => '10045']);
wo(($dry['ok'] ?? false) === true && ($dry['dry'] ?? false) === true, 'hook utan dry är torr');
$dryRow = $dry['orders'][0] ?? [];
$dryIds = array_column($dryRow['leads'] ?? [], 'thread_id');
sort($dryIds);
$want = [$lead['thread_id'], $phoneLead['thread_id'], $addrLead['thread_id']];
sort($want);
wo(($dryRow['found'] ?? false) === true && ($dryRow['reminder_id'] ?? 1) === 0 && ($dryRow['fortnox'] ?? '') === 'dry' && $dryIds === $want, 'dry listar träffade leadtrådar och skriver inget');
wo(!in_array($other['thread_id'], $dryIds, true) && !in_array($stranger['thread_id'], $dryIds, true), 'annan produkt och annan kund listas inte');
wo(wo_status($lead['thread_id']) === 'open' && count(jeab_list_reminders($store, true)) === 0 && $GLOBALS['JEAB_MAILS'] === 0, 'dry stänger inget och skapar ingen påminnelse');

$wet = jeab_inkorg_hook(['action' => 'inbox.webshop_orders', 'order' => '10045', 'dry' => 0]);
$row = $wet['orders'][0] ?? [];
wo(($row['reminder_id'] ?? 0) > 0 && ($row['duplicate'] ?? true) === false && ($row['booking_started'] ?? true) === false && ($row['fortnox'] ?? '') === 'linked' && ($row['contact'] ?? '') !== '', 'dry=0 skapar en påminnelse, länkar Fortnox och en kontakt');
$rem = jeab_get_reminder($store, (int) $row['reminder_id']);
wo(in_array('Elin', jeab_assignees_from_row($rem), true), 'Järfälla laddbox tilldelas Elin');
wo(in_array('vunnen', jeab_parse_tags($rem['tags'] ?? []), true), 'påminnelsen är vunnen');
$purchase = jeab_purchase_get((int) $row['reminder_id']);
wo(is_array($purchase) && ($purchase['kind'] ?? '') === 'laddbox' && (string) ($purchase['kundpris'] ?? '') === '8490' && ($purchase['fortnox_order'] ?? '') === '4401', 'order har produkt, belopp och Fortnox-länk');
wo(str_contains((string) ($rem['note'] ?? ''), 'Easee Home laddbox') && str_contains((string) ($rem['note'] ?? ''), '8 490'), 'anteckningen har produkt och belopp');
wo(($row['thread_closed'] ?? false) === true && wo_status((string) $old['thread_id']) === 'closed', 'ordertråden stängs');
wo(str_contains(wo_notes((string) $old['thread_id']), 'Vunnen') && str_contains(wo_notes((string) $old['thread_id']), 'p=ordrar&id=' . $row['reminder_id']), 'ordertråden får intern länk');
$closedLeads = array_column(array_filter($row['leads'] ?? [], static fn (array $l): bool => !empty($l['closed'])), 'thread_id');
sort($closedLeads);
wo($closedLeads === $want && wo_status($other['thread_id']) === 'closed' && wo_status($stranger['thread_id']) === 'open', 'samma kundmejl stängs, en annan kund lämnas öppen');
$linked = jeab_get_reminder($store, (int) $row['reminder_id']);
wo(str_contains(wo_notes($lead['thread_id']), 'Vunnen') && str_contains((string) ($linked['note'] ?? ''), jeab_lead_mail_url($lead['thread_id'])), 'leden länkas till samma påminnelse');
wo(count(jeab_list_reminders($store, true)) === 1 && $GLOBALS['JEAB_MAILS'] === 0, 'ingen andra påminnelse och inget kundutskick');

$again = jeab_inkorg_hook(['action' => 'inbox.webshop_orders', 'all' => 1, 'dry' => 0]);
$againFresh = array_values(array_filter(
    is_array($again['orders'] ?? null) ? $again['orders'] : [],
    static fn (mixed $r): bool => is_array($r) && empty($r['already_done'])
));
wo($againFresh === [] && ($again['orders'][0]['already_done'] ?? false) === true && ($again['orders'][0]['number'] ?? '') === '10045' && count(jeab_list_reminders($store, true)) === 1, 'andra körningen rapporterar already_done och skriver inget');
$direct = jeab_webshop_inbox_apply($store, jeab_inkorg_get((string) $old['id']), false);
wo(!empty($direct['duplicate']) && (int) $direct['reminder_id'] === (int) $row['reminder_id'] && count(jeab_list_reminders($store, true)) === 1, 'samma order igen skapar inget nytt');
wo(substr_count(wo_notes((string) $old['thread_id']), 'Vunnen') === 1, 'anteckningen skrivs bara en gång');

$plain = wo_body('10060', 'Easee Home laddbox', 'Mira Exempel', 'mira.exempel@example.com', '070-111 22 33', 'Exempelvägen 12', '177 31', 'Järfälla');
$live = wo_ingest('live-mira', 'info@stores-emails.com', 'Betald order', $plain);
wo(($live['labels']['type'] ?? '') === 'Order', 'ny butiksorder klassas Order vid intag');
$auto = jeab_auto_on_message((string) $live['id']);
wo(($auto['action'] ?? '') === 'webshop_order' && (int) ($auto['webshop']['reminder_id'] ?? 0) > 0 && (int) ($auto['webshop']['reminder_id'] ?? 0) !== (int) $row['reminder_id'], 'nytt betalt mejl startar orderflödet');
wo(count(jeab_list_reminders($store, true)) === 2, 'andra ordernumret blir en egen påminnelse');

$skipBody = "Ny beställning mottagen\nEasee Charge Up laddbox\nNamn: Kim Order\nE-post: kim.order.sq@gmail.com\nTelefon: 070-404 11 22\nAdress: Ordervägen 8, 176 70 Järfälla\n";
$skip = wo_ingest('skip-store', 'info@stores-emails.com', 'Ny beställning', $skipBody, 'jarfalla', [
    'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 100, 'intent' => 'kop', 'offer' => 'laddbox',
]);
$skipAuto = jeab_auto_on_message((string) $skip['id']);
wo(($skipAuto['action'] ?? '') === 'skip' && str_contains((string) ($skipAuto['why'] ?? ''), 'butiksorder') && wo_status((string) $skip['thread_id']) !== 'closed', 'obetald butiksorder stängs inte och blir ingen påminnelse');

$beforeContacts = count(jeab_kontakter_load());
$otherStreet = jeab_kontakt_find_or_create([
    'name' => 'Sara Exempel',
    'email' => 'sara.exempel@example.com',
    'phone' => '070-333 44 55',
    'address' => 'Annanvägen 9, 177 31 Järfälla',
    'postal_code' => '17731',
    'city' => 'Järfälla',
], false);
$saraBody = wo_body('10070', 'SigenStor Neo', 'Sara Exempel', 'sara.exempel@example.com', '070-333 44 55', 'Exempelvägen 3', '177 31', 'Järfälla');
$sara = wo_ingest('sara', 'info@stores-emails.com', 'Kvitto', $saraBody, 'jarfalla', $info);
$saraWet = jeab_inkorg_hook(['action' => 'inbox.webshop_orders', 'order' => '10070', 'dry' => 0]);
$saraRow = $saraWet['orders'][0] ?? [];
$saraRem = jeab_get_reminder($store, (int) ($saraRow['reminder_id'] ?? 0));
wo(in_array('Roberto', jeab_assignees_from_row($saraRem ?: []), true), 'batteri tilldelas Roberto');
wo(($saraRow['contact'] ?? '') === (string) ($otherStreet['id'] ?? '') && count(jeab_kontakter_load()) === $beforeContacts + 1, 'samma e-post återanvänder kontakten och skriver ingen ny adress');
$kept = jeab_kontakter_load()[(string) $otherStreet['id']] ?? [];
wo(str_contains((string) ($kept['street'] ?? ''), 'Annanvägen'), 'den gamla gatan ligger kvar');

$solBody = wo_body('10071', 'Solceller 10 kW', 'Liv Exempel', 'liv.exempel@example.com', '070-555 66 77', 'Solvägen 4', '177 31', 'Järfälla');
$sol = wo_ingest('liv', 'info@stores-emails.com', 'Kvitto', $solBody, 'jarfalla', $info);
$solWet = jeab_inkorg_hook(['action' => 'inbox.webshop_orders', 'order' => '10071', 'dry' => 0]);
$solRem = jeab_get_reminder($store, (int) ($solWet['orders'][0]['reminder_id'] ?? 0));
wo(in_array('Sam L', jeab_assignees_from_row($solRem ?: []), true) && (jeab_purchase_get((int) $solRem['id'])['kind'] ?? '') === 'sol', 'sol utan batteri tilldelas Sam L');

$badPhone = wo_body('10072', 'Easee Home laddbox', 'Bo Exempel', 'bo.exempel@example.com', '123', 'Exempelvägen 8', '177 31', 'Järfälla');
$bad = wo_ingest('bo', 'info@stores-emails.com', 'Kvitto', $badPhone, 'jarfalla', $info);
$badWet = jeab_inkorg_hook(['action' => 'inbox.webshop_orders', 'order' => '10072', 'dry' => 0]);
$badRem = jeab_get_reminder($store, (int) ($badWet['orders'][0]['reminder_id'] ?? 0));
wo(is_array($badRem) && trim((string) ($badRem['phone'] ?? '')) === '', 'ogiltigt telefonnummer stoppar inte ordern');

$priorLead = wo_lead('lead-prior', 'pia.exempel@example.com', 'Pia Exempel', "Hej, laddbox.\nE-post: pia.exempel@example.com\nTelefon: 070-444 55 66\nAdress: Exempelvägen 15, 177 31 Järfälla\n", 'laddbox');
$prior = jeab_lead_process(jeab_auto_thread_by_id((string) $priorLead['thread_id']), true);
$priorId = (int) ($prior['reminder'] ?? 0);
wo($priorId > 0, 'ledet hade redan en påminnelse');
$piaBody = wo_body('10073', 'Easee Home laddbox', 'Pia Exempel', 'pia.exempel@example.com', '070-444 55 66', 'Exempelvägen 15', '177 31', 'Järfälla');
$pia = wo_ingest('pia', 'info@stores-emails.com', 'Kvitto', $piaBody, 'jarfalla', $info);
$nBefore = count(jeab_list_reminders($store, true));
$piaWet = jeab_inkorg_hook(['action' => 'inbox.webshop_orders', 'order' => '10073', 'dry' => 0]);
$piaId = (int) ($piaWet['orders'][0]['reminder_id'] ?? 0);
$priorRem = jeab_get_reminder($store, $priorId);
wo($piaId > 0 && $piaId !== $priorId && count(jeab_list_reminders($store, true)) === $nBefore + 1, 'vunnen lead skapar ingen extra påminnelse utöver ordern');
wo(!in_array('vunnen', jeab_parse_tags($priorRem['tags'] ?? []), true), 'ledets gamla påminnelse blir inte en andra vunnen affär');
wo(str_contains((string) jeab_get_reminder($store, $piaId)['note'], jeab_lead_mail_url((string) $priorLead['thread_id'])), 'orderpåminnelsen pekar på leadtråden');

$malmoBody = wo_body('10080', 'Easee Home laddbox', 'Noel Exempel', 'noel.exempel@example.com', '070-666 77 88', 'Exempelgatan 2', '211 20', 'Malmö');
$malmo = wo_ingest('noel', 'info@stores-emails.com', 'Kvitto', $malmoBody, 'malmo', $info);
$malmoWet = jeab_inkorg_hook(['action' => 'inbox.webshop_orders', 'order' => '10080', 'dry' => 0]);
$malmoRow = $malmoWet['orders'][0] ?? [];
$malmoRem = jeab_get_reminder($store, (int) ($malmoRow['reminder_id'] ?? 0));
$malmoPurchase = jeab_purchase_get((int) ($malmoRow['reminder_id'] ?? 0)) ?? [];
wo(in_array('Kim', jeab_assignees_from_row($malmoRem ?: []), true) && empty($malmoRow['booking_started']) && (int) ($malmoPurchase['booking_id'] ?? 0) === 0, 'Malmö tilldelas Kim och bokar inte förrän Bekräfta');

$wixOrder = [
    'number' => '10090',
    'id' => 'wix-10090',
    'status' => 'APPROVED',
    'paymentStatus' => 'PAID',
    'priceSummary' => ['total' => ['amount' => '1111.00']],
    'buyerInfo' => ['email' => 'wix.exempel@example.com'],
    'billingInfo' => [
        'address' => ['addressLine' => 'Exempelvägen 20', 'postalCode' => '177 31', 'city' => 'Järfälla'],
        'contactDetails' => ['firstName' => 'Wix', 'lastName' => 'Exempel', 'phone' => '+46707776655'],
    ],
    'lineItems' => [[
        'productName' => ['original' => 'Easee Home laddbox'],
        'quantity' => 1,
        'price' => ['amount' => '1111.00'],
    ]],
];
$GLOBALS['_JEAB_WIX_REQUEST'] = static function (string $site, string $method, string $path, ?array $body) use ($wixOrder): array {
    unset($site, $method, $path);
    $want = (string) ($body['search']['filter']['number']['$eq'] ?? '');
    if ($want === '10091') {
        $un = $wixOrder;
        $un['number'] = '10091';
        $un['paymentStatus'] = 'NOT_PAID';
        return ['orders' => [$un]];
    }
    if ($want === '10092') {
        throw new RuntimeException('Wix nere');
    }
    if ($want === '10090') {
        return ['orders' => [$wixOrder]];
    }
    return ['orders' => []];
};
$wixBody = wo_body('10090', 'Easee Home laddbox', 'Wix Exempel', 'wix.exempel@example.com', '070-777 66 55', 'Exempelvägen 20', '177 31', 'Järfälla');
$wix = wo_ingest('wix', 'info@stores-emails.com', 'Kvitto', $wixBody, 'jarfalla', $info);
$wixWet = jeab_inkorg_hook(['action' => 'inbox.webshop_orders', 'order' => '10090', 'dry' => 0]);
$wixPurchase = jeab_purchase_get((int) ($wixWet['orders'][0]['reminder_id'] ?? 0)) ?? [];
wo((string) ($wixPurchase['kundpris'] ?? '') === '1111' && ($wixPurchase['webshop']['id'] ?? '') === 'wix-10090', 'Wix-ordern används när den finns och är betald');

$unBody = wo_body('10091', 'Easee Home laddbox', 'Ove Exempel', 'ove.exempel@example.com', '070-121 21 21', 'Exempelvägen 21', '177 31', 'Järfälla');
$un = wo_ingest('ove', 'info@stores-emails.com', 'Kvitto', $unBody, 'jarfalla', $info);
$unWet = jeab_inkorg_hook(['action' => 'inbox.webshop_orders', 'order' => '10091', 'dry' => 0]);
wo(($unWet['orders'][0]['skipped'] ?? '') === 'not_paid' && (int) ($unWet['orders'][0]['reminder_id'] ?? 0) === 0, 'Wix som inte är betald skapar ingen påminnelse');
wo(jeab_inkorg_get((string) $un['id'])['labels']['type'] === 'Information', 'obetald enligt Wix lämnas som Information');

$fallBody = wo_body('10092', 'Easee Home laddbox', 'Ebba Exempel', 'ebba.exempel@example.com', '070-131 31 31', 'Exempelvägen 22', '177 31', 'Järfälla');
$fall = wo_ingest('ebba', 'info@stores-emails.com', 'Kvitto', $fallBody, 'jarfalla', $info);
$fallWet = jeab_inkorg_hook(['action' => 'inbox.webshop_orders', 'order' => '10092', 'dry' => 0]);
$fallPurchase = jeab_purchase_get((int) ($fallWet['orders'][0]['reminder_id'] ?? 0)) ?? [];
wo((int) ($fallWet['orders'][0]['reminder_id'] ?? 0) > 0 && (string) ($fallPurchase['kundpris'] ?? '') === '8490', 'trasig Wix-läsning faller tillbaka på mejlet');
unset($GLOBALS['_JEAB_WIX_REQUEST']);

wo_ingest('early', 'info@stores-emails.com', 'Kvitto', wo_body('10093', 'Easee Home laddbox', 'Tid Exempel', 'tid.exempel@example.com', '070-141 41 41', 'Exempelvägen 23', '177 31', 'Järfälla'), 'jarfalla', $info, '2026-09-01T09:00:00+02:00');
wo_ingest('late', 'info@stores-emails.com', 'Kvitto', wo_body('10094', 'Easee Home laddbox', 'Sen Exempel', 'sen.exempel@example.com', '070-151 51 51', 'Exempelvägen 24', '177 31', 'Järfälla'), 'jarfalla', $info, '2026-10-02T09:00:00+02:00');
$since = jeab_inkorg_hook(['action' => 'inbox.webshop_orders', 'all' => 1, 'since' => '2026-10-01']);
$sinceNums = array_column($since['orders'] ?? [], 'number');
wo(!in_array('10093', $sinceNums, true) && in_array('10094', $sinceNums, true), 'since hoppar över äldre order och visar nyare');
$one = jeab_inkorg_hook(['action' => 'inbox.webshop_orders', 'order' => '10093', 'dry' => 1]);
wo(($one['orders'][0]['found'] ?? false) === true && ($one['orders'][0]['number'] ?? '') === '10093', 'order= träffar ett nummer');
wo((jeab_inkorg_hook(['action' => 'inbox.webshop_orders'])['ok'] ?? true) === false, 'hooken kräver order eller all=1');
wo($GLOBALS['JEAB_MAILS'] === 0, 'inget systemmejl gick ut');

$orderPreset = ['type' => 'Order', 'action' => 'arkivera', 'priority' => 3, 'confidence' => 80, 'source' => 'llm'];
$noraBody = wo_body('10110', 'Easee Home laddbox', 'Nora Exempel', 'nora.exempel@example.com', '070-161 61 61', 'Exempelvägen 30', '177 31', 'Järfälla');
$nora = wo_ingest('nora-order', 'info@stores-emails.com', 'Kvitto Nora 10110', $noraBody, 'jarfalla', $orderPreset);
wo(($nora['labels']['type'] ?? '') === 'Order', 'förklassad order ligger kvar som Order');
$beforeNora = count(jeab_list_reminders($store, true));
$findsNora = (int) $GLOBALS['JEAB_FORTNOX_FINDS'];
$writesNora = (int) $GLOBALS['JEAB_FORTNOX_WRITES'];
$noraDry = jeab_inkorg_hook(['action' => 'inbox.webshop_orders', 'order' => '10110']);
wo(($noraDry['dry'] ?? false) === true && ($noraDry['orders'][0]['found'] ?? false) === true && empty($noraDry['orders'][0]['already_done']) && (int) ($noraDry['orders'][0]['reminder_id'] ?? 1) === 0 && ($noraDry['orders'][0]['fortnox'] ?? '') === 'dry' && count(jeab_list_reminders($store, true)) === $beforeNora && (int) $GLOBALS['JEAB_FORTNOX_WRITES'] === $writesNora, 'torr hook tar med Order utan påminnelse och skriver inget');
$noraWet = jeab_inkorg_hook(['action' => 'inbox.webshop_orders', 'order' => '10110', 'dry' => 0]);
$noraRow = $noraWet['orders'][0] ?? [];
$noraId = (int) ($noraRow['reminder_id'] ?? 0);
wo(empty($noraRow['already_done']) && $noraId > 0 && ($noraRow['fortnox'] ?? '') === 'linked' && count(jeab_list_reminders($store, true)) === $beforeNora + 1 && (int) $GLOBALS['JEAB_FORTNOX_FINDS'] === $findsNora + 1 && (int) $GLOBALS['JEAB_FORTNOX_WRITES'] === $writesNora, 'Order utan påminnelse blir en vunnen påminnelse och länkar Fortnox utan att skriva en ny');
$noraPurchase = jeab_purchase_get($noraId) ?? [];
wo((string) ($noraPurchase['fortnox_order'] ?? '') === '4401', 'länken är den befintliga Fortnox-ordern');
$noraSnap = json_encode($noraPurchase);
$noraNotes = substr_count(wo_notes((string) $nora['thread_id']), 'Vunnen');
$findsLinked = (int) $GLOBALS['JEAB_FORTNOX_FINDS'];
$noraAgain = jeab_inkorg_hook(['action' => 'inbox.webshop_orders', 'order' => '10110', 'dry' => 0]);
$noraAgainRow = $noraAgain['orders'][0] ?? [];
wo(!empty($noraAgainRow['already_done']) && ($noraAgainRow['key'] ?? '') === 'jarfalla:10110' && (int) ($noraAgainRow['reminder_id'] ?? 0) === $noraId && ($noraAgainRow['fortnox'] ?? '') === 'already' && count(jeab_list_reminders($store, true)) === $beforeNora + 1, 'Order med påminnelse och Fortnox-order rapporteras already_done');
wo((int) $GLOBALS['JEAB_FORTNOX_FINDS'] === $findsLinked && (int) $GLOBALS['JEAB_FORTNOX_WRITES'] === $writesNora && json_encode(jeab_purchase_get($noraId) ?? []) === $noraSnap && substr_count(wo_notes((string) $nora['thread_id']), 'Vunnen') === $noraNotes, 'already_done skriver ingen Fortnox-order och ingen ny anteckning');
$noraAuto = jeab_auto_on_message((string) $nora['id']);
wo(($noraAuto['action'] ?? '') === 'already_done' && (int) ($noraAuto['webshop']['reminder_id'] ?? 0) === $noraId && (int) $GLOBALS['JEAB_FORTNOX_FINDS'] === $findsLinked && (int) $GLOBALS['JEAB_FORTNOX_WRITES'] === $writesNora && count(jeab_list_reminders($store, true)) === $beforeNora + 1, 'automatiken skriver inget när ordernyckeln redan är klar');

$rutIn = jeab_webshop_ingest($store, [
    'company' => 'jarfalla',
    'order' => [
        'number' => '10112',
        'status' => 'APPROVED',
        'paymentStatus' => 'PAID',
        'priceSummary' => ['total' => ['amount' => '8490']],
        'buyerInfo' => ['email' => 'rut.exempel@example.com'],
        'billingInfo' => [
            'address' => ['addressLine' => 'Exempelvägen 32', 'postalCode' => '177 31', 'city' => 'Järfälla'],
            'contactDetails' => ['firstName' => 'Rut', 'lastName' => 'Exempel', 'phone' => '0701721721'],
        ],
        'lineItems' => [[
            'productName' => ['original' => 'Easee Home laddbox'],
            'quantity' => 1,
            'price' => ['amount' => '8490'],
        ]],
    ],
]);
$rutId = (int) ($rutIn['reminder_id'] ?? 0);
wo($rutId > 0 && empty($rutIn['duplicate']) && trim((string) (jeab_purchase_get($rutId)['fortnox_order'] ?? '')) === '', 'påminnelse utan Fortnox-order finns i indexet');
$rutBody = wo_body('10112', 'Easee Home laddbox', 'Rut Exempel', 'rut.exempel@example.com', '070-172 17 21', 'Exempelvägen 32', '177 31', 'Järfälla');
$rut = wo_ingest('rut-order', 'info@stores-emails.com', 'Kvitto Rut 10112', $rutBody, 'jarfalla', $orderPreset);
$beforeRut = count(jeab_list_reminders($store, true));
$findsRut = (int) $GLOBALS['JEAB_FORTNOX_FINDS'];
$rutWet = jeab_inkorg_hook(['action' => 'inbox.webshop_orders', 'order' => '10112', 'dry' => 0]);
$rutRow = $rutWet['orders'][0] ?? [];
wo(!empty($rutRow['already_done']) && (int) ($rutRow['reminder_id'] ?? 0) === $rutId && ($rutRow['fortnox'] ?? '') === 'missing' && count(jeab_list_reminders($store, true)) === $beforeRut && (int) $GLOBALS['JEAB_FORTNOX_FINDS'] === $findsRut && (int) $GLOBALS['JEAB_FORTNOX_WRITES'] === $writesNora && wo_status((string) $rut['thread_id']) !== 'closed' && !str_contains(wo_notes((string) $rut['thread_id']), 'Vunnen'), 'Order med påminnelse hoppas över och skriver inget');

$index = jeab_webshop_index();
$index['jarfalla:10113'] = ['reminder_id' => 880013, 'created_at' => jeab_now(), 'wix_id' => 'wix-test-10113'];
jeab_webshop_index_save($index);
jeab_purchase_put([
    'reminder_id' => 880013,
    'fortnox_order' => '8801',
    'source' => 'webshop',
    'company' => 'jarfalla',
    'name' => 'Siv Exempel',
    'email' => 'siv.exempel@example.com',
    'webshop' => [
        'platform' => 'wix',
        'company' => 'jarfalla',
        'number' => '10113',
        'id' => 'wix-test-10113',
        'total' => '8490',
        'email' => 'siv.exempel@example.com',
        'name' => 'Siv Exempel',
        'lines' => [],
    ],
]);
wo(jeab_get_reminder($store, 880013) === null, 'Fortnox-ordern saknar påminnelse');
$sivBody = wo_body('10113', 'Easee Home laddbox', 'Siv Exempel', 'siv.exempel@example.com', '070-173 17 31', 'Exempelvägen 33', '177 31', 'Järfälla');
$siv = wo_ingest('siv-order', 'info@stores-emails.com', 'Kvitto Siv 10113', $sivBody, 'jarfalla', $orderPreset);
$beforeSiv = count(jeab_list_reminders($store, true));
$findsSiv = (int) $GLOBALS['JEAB_FORTNOX_FINDS'];
$sivSnap = json_encode(jeab_purchase_get(880013) ?? []);
$sivWet = jeab_inkorg_hook(['action' => 'inbox.webshop_orders', 'order' => '10113', 'dry' => 0]);
$sivRow = $sivWet['orders'][0] ?? [];
wo(!empty($sivRow['already_done']) && (int) ($sivRow['reminder_id'] ?? 0) === 880013 && ($sivRow['fortnox'] ?? '') === 'already' && count(jeab_list_reminders($store, true)) === $beforeSiv && (int) $GLOBALS['JEAB_FORTNOX_FINDS'] === $findsSiv && (int) $GLOBALS['JEAB_FORTNOX_WRITES'] === $writesNora && json_encode(jeab_purchase_get(880013) ?? []) === $sivSnap && wo_status((string) $siv['thread_id']) !== 'closed', 'Order med Fortnox-order hoppas över och skriver ingen ny');

$olleBody = wo_body('10111', 'Easee Home laddbox', 'Olle Exempel', 'olle.exempel@example.com', '070-171 71 71', 'Exempelvägen 31', '177 31', 'Järfälla');
$olle = wo_ingest('olle-order', 'info@stores-emails.com', 'Kvitto Olle 10111', $olleBody, 'jarfalla', $orderPreset);
$beforeOlle = count(jeab_list_reminders($store, true));
$findsOlle = (int) $GLOBALS['JEAB_FORTNOX_FINDS'];
$olleAuto = jeab_auto_on_message((string) $olle['id']);
wo(($olleAuto['action'] ?? '') === 'webshop_order' && empty($olleAuto['webshop']['already_done']) && (int) ($olleAuto['webshop']['reminder_id'] ?? 0) > 0 && count(jeab_list_reminders($store, true)) === $beforeOlle + 1 && (int) $GLOBALS['JEAB_FORTNOX_FINDS'] === $findsOlle + 1 && (int) $GLOBALS['JEAB_FORTNOX_WRITES'] === $writesNora, 'automatiken tar Order utan påminnelse');
wo($GLOBALS['JEAB_MAILS'] === 0, 'inget systemmejl gick ut efter Order-uppföljningen');

echo "Alla inbox-webshoptester OK\n";
