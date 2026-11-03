<?php

declare(strict_types=1);

/** Nyhetsbrev, köpt lead och privatkund. Omklassning flyttar bara och skickar inget. */

$tmp = sys_get_temp_dir() . '/jeab-klass-sort-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ks(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$src = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_klassning.php');
ks(!str_contains($src, 'file_put_contents') && !str_contains($src, 'jeab_data_file') && !str_contains($src, 'jeab_inkorg_locked'), 'klassningen skriver ingen JSON');
ks(!str_contains($src, 'jeab_kontakter_load'), 'klassningen läser inte hela kontaktregistret');
ks(str_contains($src, 'function jeab_inkorg_klassning_reclassify') && str_contains($src, 'function jeab_inkorg_klassning_reclassify_undo'), 'omklassning och ångra finns');

$plain = static function (string $type, string $intent, int $conf = 0): array {
    return ['type' => $type, 'intent' => $intent, 'confidence' => $conf, 'action' => 'svara', 'priority' => 3, 'needs_review' => $conf < 80];
};

$news = ['from' => 'info@kompetensutveckla.se', 'from_name' => 'Kompetensutveckla', 'subject' => 'SKYDDSOMBUDENS DAG', 'body' => "Välkomna.\nAvregistrera: https://kompetensutveckla.se/unsubscribe", 'labels' => $plain('Oklassad', '')];
ks(jeab_inkorg_klassning_newsletter($news) && jeab_inkorg_klassning_route($news) === 'skrap', 'nyhetsbrev utan modellklass är skräp');
ks(jeab_inkorg_klassning_route(['from' => 'news@brand.se', 'subject' => 'Veckans reklam', 'body' => 'Hej', 'headers' => ['List-Unsubscribe' => '<mailto:x@brand.se>'], 'labels' => $plain('Oklassad', '')]) === 'skrap', 'List-Unsubscribe är skräp');
ks(jeab_inkorg_klassning_route(['from' => 'noreply@kurs.se', 'subject' => 'Påminnelse', 'body' => 'Hej', 'labels' => $plain('Oklassad', '')]) === 'skrap', 'no-reply är skräp');

$hitta = [
    'from' => 'leads@hitta-solceller.se',
    'from_name' => 'Hitta-Solceller',
    'subject' => 'Hitta-Solceller.se – Ny offert',
    'body' => "Namn: Vera Exempel\nE-post: vera.hitta@gmail.com\nVi vill ha solceller.",
    'labels' => $plain('Oklassad', ''),
];
ks(jeab_inkorg_klassning_lead_sender($hitta) && jeab_inkorg_klassning_route($hitta) === 'kop' && jeab_inkorg_klassning_lead_offer($hitta) === 'sol', 'Hitta-Solceller är köpintention');
foreach ([
    'notify@offerta.se' => 'Offerta',
    'hej@soloversikt.se' => 'Solöversikt',
    'lead@solceller.nu' => 'Solceller.nu',
    'hej@hemsol.se' => 'HemSol',
    'hej@nordsolen.se' => 'Nordsolen',
    'lead@leadkit.com' => 'Leadkit',
    'hej@brabyggfirmor.se' => 'Bra Byggfirmor',
    'hej@servicefinder.se' => 'Servicefinder',
    'hej@byggahus.se' => 'Byggahus',
    'lead@omtago.se' => 'Omtago',
    'lead@weply.se' => 'Weply',
    'form@kalkyl.jarfalla.example.test' => 'Kalkyl',
] as $from => $name) {
    $probe = ['from' => $from, 'from_name' => $name, 'subject' => 'Ny förfrågan', 'body' => 'Hej', 'labels' => $plain('Information', 'information', 90)];
    ks(jeab_inkorg_klassning_lead_sender($probe) && jeab_inkorg_klassning_route($probe) === 'kop', $name . ' är köpintention');
}

$ulf = ['from' => 'ulf.hedenstrom@hedenstrom.example', 'from_name' => 'Ulf Hedenström', 'subject' => 'Installation av laddbox', 'body' => 'Hej, jag hörde av mig om laddboxen.', 'labels' => $plain('Information', 'information', 92)];
ks(jeab_inkorg_klassning_private_person($ulf) && jeab_inkorg_klassning_route($ulf) === 'inkorg' && !jeab_inkorg_klassning_partner_info($ulf), 'privatperson är aldrig information');
$gmail = ['from' => 'anna@gmail.com', 'from_name' => 'Anna Svensson', 'subject' => 'Info', 'body' => 'Hej', 'labels' => $plain('Information', 'information', 90)];
ks(jeab_inkorg_klassning_route($gmail) === 'inkorg', 'gmail-kund stannar i inkorgen');
$supplier = ['from' => 'order@supplier.se', 'from_name' => 'Anna Svensson', 'subject' => 'Månadsrapport från leverantören', 'body' => 'Ingen åtgärd behövs.', 'labels' => $plain('Information', 'information', 90)];
ks(jeab_inkorg_klassning_route($supplier) === 'information', 'leverantör utan åtgärd är information');
$contactPdo = jeab_contact_store_pdo();
ks($contactPdo instanceof PDO, 'kontakt-SQL finns');
jeab_settings_put($contactPdo, jeab_driver(), JEAB_KONTAKTER_STORAGE_KEY, 'sql');
unset($GLOBALS['_JEAB_KLASSNING_SQL']);
jeab_kontakter_mutate(static function (array &$rows): null {
    $rows['k-kand'] = ['owners' => [['name' => 'Bo Kund', 'email' => 'bo.kund@firma.example']]];

    return null;
});
unset($GLOBALS['_JEAB_KLASSNING_KNOWN'], $GLOBALS['_JEAB_KLASSNING_CONTACT_SQL']);
$known = ['from' => 'bo.kund@firma.example', 'from_name' => 'Firma', 'subject' => 'Angående fakturan', 'body' => 'Hej', 'labels' => $plain('Information', 'information', 95)];
$loadsBefore = (int) ($GLOBALS['_JEAB_KONTAKTER_LOADS'] ?? 0);
ks(jeab_inkorg_klassning_known_contact('bo.kund@firma.example') && jeab_inkorg_klassning_route($known) === 'information' && jeab_inkorg_klassning_quiet_information($known), 'känd företagskontakt med Information lämnar Inkorgen');
$knownPerson = ['from' => 'bo.kund@firma.example', 'from_name' => 'Bo Kund', 'subject' => 'Angående fakturan', 'body' => 'Hej', 'labels' => $plain('Information', 'information', 95)];
ks(jeab_inkorg_klassning_route($knownPerson) === 'inkorg' && !jeab_inkorg_klassning_quiet_information($knownPerson), 'känd privatperson med Information stannar i Inkorgen');
ks((int) ($GLOBALS['_JEAB_KONTAKTER_LOADS'] ?? 0) === $loadsBefore, 'känd kontakt läser inte hela registret');
$defekt = ['from' => 'sara@telia.com', 'from_name' => 'Sara', 'subject' => 'Re: Defekt laddbox?', 'body' => 'Laddboxen är defekt.', 'labels' => $plain('Information', 'information', 90)];
ks(jeab_inkorg_klassning_route($defekt) === 'inkorg', 'defekt laddbox är kundpost');
$ater = ['from' => 'bo@exempel-bygg.se', 'from_name' => '', 'subject' => 'Återmontering av solceller', 'body' => 'När kan ni återmontera?', 'labels' => $plain('Information', 'information', 90)];
ks(jeab_inkorg_klassning_customer_mail($ater) && jeab_inkorg_klassning_route($ater) === 'inkorg', 'återmontering är kundpost');

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;

    return ['ok' => true, 'from' => 'saljare4@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
foreach (['laddbox_jarfalla', 'batteri_jarfalla', 'sol_jarfalla'] as $pdfKey) {
    jeab_auto_pdf_save(['key' => $pdfKey, 'data' => base64_encode('%PDF-1.4 ' . $pdfKey)]);
}

$in = static function (string $id, array $msg, string $source = 'graph-jarfalla', string $box = 'kontakt@jarfalla.example.test'): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@klass-sort.test',
        'from' => (string) $msg['from'],
        'from_name' => (string) ($msg['from_name'] ?? ''),
        'to' => $box,
        'subject' => (string) $msg['subject'],
        'body_text' => (string) ($msg['body'] ?? ''),
        'headers' => (array) ($msg['headers'] ?? []),
        'received_at' => (string) ($msg['received_at'] ?? date('c')),
        'source' => $source,
        'graph_mailbox' => $box,
        'preset_labels' => (array) $msg['labels'],
    ]);
};
$thread = static function (string $tid): array {
    foreach (jeab_inkorg_threads() as $t) {
        if ((string) ($t['id'] ?? '') === $tid) {
            return $t;
        }
    }

    return [];
};
$rems = static function (): int {
    return count(jeab_list_reminders(jeab_store(), true));
};

$newsIn = $in('news', $news);
$newsAuto = jeab_auto_on_message((string) $newsIn['id']);
$newsThread = $thread((string) $newsIn['thread_id']);
ks(($newsAuto['action'] ?? '') === 'historik' && ($newsAuto['sent'] ?? 1) === 0 && ($newsThread['status'] ?? '') === 'closed' && jeab_inkorg_folder_thread($newsThread, 'historik') && !jeab_inkorg_folder_thread($newsThread, 'inkorg'), 'nyhetsbrevet hamnar i Historik');
ks($GLOBALS['_SENT'] === [], 'nyhetsbrevet skickade inget');

$ulfIn = $in('ulf', $ulf);
$ulfAuto = jeab_auto_on_message((string) $ulfIn['id']);
$ulfThread = $thread((string) $ulfIn['thread_id']);
ks(($ulfAuto['action'] ?? '') === 'none' && ($ulfThread['status'] ?? '') === 'open' && jeab_inkorg_folder_thread($ulfThread, 'information') && !jeab_inkorg_folder_thread($ulfThread, 'inkorg'), 'Ulf är typen Information och ligger i Information');

$sentBefore = count($GLOBALS['_SENT']);
$remBefore = $rems();
$hittaIn = $in('hitta', $hitta);
$hittaAuto = jeab_auto_on_message((string) $hittaIn['id']);
$hittaThread = $thread((string) $hittaIn['thread_id']);
ks(in_array((string) ($hittaAuto['action'] ?? ''), ['quoted', 'hold', 'historik'], true) && ($hittaThread['status'] ?? '') === 'closed' && jeab_inkorg_folder_thread($hittaThread, 'historik'), 'köpt lead hamnar i Historik: ' . (string) ($hittaAuto['action'] ?? '') . ' ' . (string) ($hittaAuto['why'] ?? ''));
ks($rems() > $remBefore, 'köpt lead får en påminnelse');
$hittaMail = jeab_inkorg_get((string) $hittaIn['id']);
ks(is_array($hittaMail) && (string) ($hittaMail['labels']['type'] ?? '') === 'Ny lead' && (string) ($hittaMail['labels']['offer'] ?? '') === 'sol', 'köpt lead märks som offert');

$defekt['received_at'] = date('c', time() - 2 * 86400);
$defIn = $in('defekt', $defekt, 'graph-malmo', 'kontakt@malmo.example.test');
jeab_inkorg_thread_write((string) $defIn['thread_id'], static function (array $t): array {
    $t['status'] = 'closed';
    $t['hook_closed'] = 'information';
    $t['updated_at'] = date('c', time() - 2 * 86400);

    return $t;
});
$ater['received_at'] = date('c', time() - 3 * 86400);
$aterIn = $in('ater', $ater, 'graph-malmo', 'kontakt@malmo.example.test');
jeab_inkorg_thread_write((string) $aterIn['thread_id'], static function (array $t): array {
    $t['status'] = 'closed';
    $t['hook_closed'] = 'information';
    $t['updated_at'] = date('c', time() - 3 * 86400);

    return $t;
});
$old = $news;
$old['from'] = 'info@gammal.example';
$old['subject'] = 'Gammalt nyhetsbrev';
$old['received_at'] = date('c', time() - 40 * 86400);
$oldIn = $in('old', $old);
jeab_inkorg_thread_write((string) $oldIn['thread_id'], static function (array $t): array {
    $t['status'] = 'open';
    $t['updated_at'] = date('c', time() - 40 * 86400);

    return $t;
});
$manual = $news;
$manual['from'] = 'info@manual.example';
$manual['subject'] = 'Manuellt nyhetsbrev';
$manual['labels']['source'] = 'manual';
$manualIn = $in('manual', $manual);

$remDry = $rems();
$sentDry = count($GLOBALS['_SENT']);
$dry = jeab_inkorg_hook(['action' => 'inkorg.reclassify', 'company' => 'malmo']);
ks(($dry['ok'] ?? false) === true && ($dry['dry'] ?? 0) === 1 && ($dry['days'] ?? 0) === 30 && ($dry['company'] ?? '') === 'malmo' && ($dry['changed'] ?? 1) === 0, 'dry är 30 dagar och skriver inget');
ks($rems() === $remDry && count($GLOBALS['_SENT']) === $sentDry, 'dry skapar ingen påminnelse och skickar inget');
ks(str_contains(json_encode($dry, JSON_UNESCAPED_UNICODE) ?: '', '@') === false, 'dry-svaret har ingen e-post');
$byThread = [];
foreach ((array) ($dry['rows'] ?? []) as $row) {
    $byThread[(string) ($row['thread_id'] ?? '')] = $row;
}
ks(isset($byThread[(string) $defIn['thread_id']], $byThread[(string) $aterIn['thread_id']]), 'Malmös kundfrågor listas');
ks(($byThread[(string) $defIn['thread_id']]['old'] ?? '') === 'historik' && ($byThread[(string) $defIn['thread_id']]['new'] ?? '') === 'information', 'defekt laddbox med typen Information lämnar Historik');
ks(($byThread[(string) $aterIn['thread_id']]['old'] ?? '') === 'historik' && ($byThread[(string) $aterIn['thread_id']]['new'] ?? '') === 'information', 'återmontering med typen Information lämnar Historik');
ks(!isset($byThread[(string) $newsIn['thread_id']]) && !isset($byThread[(string) $oldIn['thread_id']]) && !isset($byThread[(string) $manualIn['thread_id']]), 'annat bolag, för gammal post och manuell etikett hoppas över');
ks(($thread((string) $defIn['thread_id'])['status'] ?? '') === 'closed', 'dry lämnar ärendet stängt');

$openLead = $hitta;
$openLead['from'] = 'leads@offerta.se';
$openLead['from_name'] = 'Offerta';
$openLead['subject'] = 'Offerta – Ny offert';
$openLead['body'] = "Namn: Otto Exempel\nE-post: otto.offerta@gmail.com\nVi vill ha en laddbox.";
$openIn = $in('offerta', $openLead);
$remWet = $rems();
$sentWet = count($GLOBALS['_SENT']);
$wet = jeab_inkorg_hook(['action' => 'inkorg.reclassify', 'dry' => 0, 'days' => 30, 'company' => 'jarfalla']);
ks(($wet['ok'] ?? false) === true && ($wet['dry'] ?? 1) === 0 && (int) ($wet['changed'] ?? 0) >= 1 && (int) ($wet['sent'] ?? 1) === 0, 'dry=0 flyttar och skickar inget');
ks(count($GLOBALS['_SENT']) === $sentWet, 'omklassningen skickade inget');
$openRow = null;
foreach ((array) ($wet['rows'] ?? []) as $row) {
    if ((string) ($row['thread_id'] ?? '') === (string) $openIn['thread_id']) {
        $openRow = $row;
    }
}
ks(is_array($openRow) && ($openRow['old'] ?? '') === 'inkorg' && ($openRow['new'] ?? '') === 'inkorg' && ($openRow['new_status'] ?? '') === 'open' && ($openRow['new_type'] ?? '') === 'Ny lead' && (int) ($openRow['remind'] ?? 0) === 1 && ($openRow['would_send'] ?? true) === false, 'Offerta blir Ny lead och lämnas öppen');
$openThread = $thread((string) $openIn['thread_id']);
ks(($openThread['status'] ?? '') === 'open' && jeab_inkorg_folder_thread($openThread, 'inkorg') && !jeab_inkorg_folder_thread($openThread, 'historik'), 'Offerta är kvar öppen i Inkorg');
ks($rems() > $remWet, 'köpintention vid omklassning får påminnelse');
$defThread = $thread((string) $defIn['thread_id']);
ks(($defThread['status'] ?? '') === 'closed', 'Järfälla-körningen rör inte Malmö');

$malmoWet = jeab_inkorg_hook(['action' => 'inkorg.reclassify', 'dry' => 0, 'days' => 30, 'company' => 'malmo']);
$defOpen = $thread((string) $defIn['thread_id']);
$aterOpen = $thread((string) $aterIn['thread_id']);
ks(($malmoWet['dry'] ?? 1) === 0 && ($defOpen['status'] ?? '') === 'open' && jeab_inkorg_folder_thread($defOpen, 'information') && !jeab_inkorg_folder_thread($defOpen, 'inkorg') && !jeab_inkorg_folder_thread($defOpen, 'historik'), 'defekt laddbox med typen Information öppnas i Information');
ks(($aterOpen['status'] ?? '') === 'open' && jeab_inkorg_folder_thread($aterOpen, 'information') && !jeab_inkorg_folder_thread($aterOpen, 'inkorg') && !jeab_inkorg_folder_thread($aterOpen, 'historik'), 'återmontering med typen Information öppnas i Information');
ks(count($GLOBALS['_SENT']) === $sentWet, 'Malmö-flytten skickade inget');

$undo = jeab_inkorg_hook(['action' => 'inkorg.reclassify_undo']);
$defBack = $thread((string) $defIn['thread_id']);
ks(($undo['ok'] ?? false) === true && (int) ($undo['undone'] ?? 0) >= 1 && (int) ($undo['sent'] ?? 1) === 0 && ($defBack['status'] ?? '') === 'closed', 'ångra lägger tillbaka stängningen och skickar inget');
$again = jeab_inkorg_hook(['action' => 'inkorg.reclassify_undo', 'batch' => (string) ($undo['batch'] ?? '')]);
ks((int) ($again['undone'] ?? 1) === 0, 'samma körning ångras bara en gång');

$smsCalls = 0;
$quoteCalls = 0;
$GLOBALS['JEAB_SMS_SEND_WATCH'] = static function () use (&$smsCalls): void {
    $smsCalls++;
};
$GLOBALS['JEAB_SMS_SEND'] = static function (): array {
    $GLOBALS['_SMS_SENT'] = true;

    return ['ok' => true];
};
$GLOBALS['JEAB_AUTO_QUOTE_WATCH'] = static function () use (&$quoteCalls): void {
    $quoteCalls++;
};
$phoneLead = [
    'from' => 'support@weply.se',
    'from_name' => 'Weply',
    'subject' => 'Weply – Ny offert batteri',
    'body' => "Namn: Nora Exempel\nTelefon: 070-111 22 33\nProdukt: Batteri\nVill ha batteri.",
    'labels' => $plain('Oklassad', ''),
];
$phoneIn = $in('phonelead', $phoneLead);
$remPhone = $rems();
$phoneWet = jeab_inkorg_hook(['action' => 'inkorg.reclassify', 'dry' => 0, 'days' => 30, 'company' => 'jarfalla']);
ks(($phoneWet['dry'] ?? 1) === 0 && $smsCalls === 0 && empty($GLOBALS['_SMS_SENT']) && $quoteCalls === 0, 'lead utan mejl skickar varken SMS eller offert');
ks($rems() > $remPhone, 'lead utan mejl får påminnelse');
ks(jeab_inkorg_klassning_no_send() === false, 'spärren släpps efter omklassningen');
$loadsList = (int) ($GLOBALS['_JEAB_KONTAKTER_LOADS'] ?? 0);
jeab_inkorg_view_total(jeab_inkorg_threads(), ['folder' => 'information']);
jeab_inkorg_view_total(jeab_inkorg_threads(), ['folder' => 'inkorg']);
ks((int) ($GLOBALS['_JEAB_KONTAKTER_LOADS'] ?? 0) === $loadsList, 'listor och räknare läser inte alla kontakter');

echo "OK  klassning-sort\n";
