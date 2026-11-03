<?php

declare(strict_types=1);

/** Engångskörning för gamla leads. Torr som standard. Inget skickas. Bara påhittade kunder. */

$tmp = sys_get_temp_dir() . '/jeab-lead-backfill-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
// Ordrarnas index ligger bara i SQL (webshop.php:42). Testet får en tom, flaggad loggdatabas som live.
$GLOBALS['_JEAB_LOG_PDO'] = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
jeab_log_flag_set(jeab_log_pdo(), 'webshop_orders');
jeab_ordrar_external_live(false);

function bf(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;
    return ['ok' => true, 'from' => 'demo@example.test', 'graph_id' => 'g', 'message_id' => 's@x'];
};
$GLOBALS['JEAB_MAILS'] = 0;
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (): array {
    $GLOBALS['JEAB_MAILS']++;
    return ['ok' => true, 'path' => 'mock', 'id' => 'm'];
};
$GLOBALS['JEAB_FORTNOX_WRITES'] = 0;
$GLOBALS['_JEAB_WEBSHOP_FORTNOX_FIND'] = static function (): array {
    return [['order' => '4401', 'customer' => '9', 'match' => 'wix_number']];
};
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method): array {
    if (strtoupper($method) !== 'GET') {
        $GLOBALS['JEAB_FORTNOX_WRITES']++;
    }
    return ['Orders' => [], 'Order' => ['DocumentNumber' => '']];
};
jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'unknown_product_ask_enabled' => 0,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
]);

$box = 'kontakt@jarfalla.example.test';
$ingest = static function (string $id, string $from, string $subject, string $body, array $labels, string $at) use ($box): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@exempel.test',
        'from' => $from,
        'from_name' => 'Kund',
        'to' => $box,
        'subject' => $subject,
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => $box,
        'thread_key' => $id,
        'received_at' => $at,
        'preset_labels' => $labels,
        'test' => true,
    ]);
};
$lead = static function (string $offer = ''): array {
    return [
        'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 90,
        'intent' => 'kop', 'offer' => $offer, 'product' => $offer, 'question' => '', 'source' => 'manual',
    ];
};
$old = '2026-10-01T08:00:00+02:00';
$form = static function (string $email, string $street, string $extra = '', string $phone = '070-100 00 01'): string {
    return "Namn: Kund\nE-post: {$email}\nTelefon: {$phone}\nAdress: {$street}, 177 30 Järfälla\n" . $extra;
};

$empty = $ingest('bf-empty', 'kund.empty@exempel.test', 'Kontaktformulär', $form('kund.empty@exempel.test', 'Testvägen 1', "Hej, jag vill bli kontaktad.\n"), $lead(), '2026-10-01T08:00:00+02:00');
$sol = $ingest('bf-sol', 'kund.sol@exempel.test', 'Sol och batteri', $form('kund.sol@exempel.test', 'Testvägen 2', "Vi vill ha solceller och batteri.\n", '070-100 00 02'), $lead('sol'), '2026-10-02T08:00:00+02:00');
$dupA = $ingest('bf-dup-a', 'kund.dup@exempel.test', 'Lead', $form('kund.dup@exempel.test', 'Testvägen 3', "Batteri till villan.\n", '070-100 00 03'), $lead('batteri'), '2026-10-03T08:00:00+02:00');
$dupB = $ingest('bf-dup-b', 'kund.dup@exempel.test', 'Lead kopia', $form('kund.dup@exempel.test', 'Testvägen 3', "Batteri till villan.\n", '070-100 00 03'), $lead('batteri'), '2026-10-04T08:00:00+02:00');
$supportIn = $ingest('bf-support', 'kund.support@exempel.test', 'Befintlig', "Vad gäller det: Support till befintlig kund\nProdukt: batteri\n" . $form('kund.support@exempel.test', 'Testvägen 4', '', '070-100 00 04'), $lead('batteri'), '2026-10-05T08:00:00+02:00');
jeab_inkorg_update((string) $supportIn['id'], static function (array $m): array {
    $m['labels']['type'] = 'Ny lead';
    $m['labels']['intent'] = 'kop';
    $m['labels']['source'] = 'manual';
    return $m;
});
$malmo = $ingest('bf-malmo', 'kund.syd@exempel.test', 'Sol i söder', "Namn: Kund\nE-post: kund.syd@exempel.test\nAdress: Storgatan 1, 211 22 Malmö\nSolceller.\n", $lead('sol'), '2026-10-06T08:00:00+02:00');
$skane = $ingest('bf-skane', 'kund.skane@exempel.test', 'Offert Skåne', $form('kund.skane@exempel.test', 'Testvägen 5', "Vi bor i Skåne och vill ha solceller.\n", '070-100 00 05'), $lead('sol'), '2026-10-07T08:00:00+02:00');
$shopBody = "Ordernummer: 90001\nBetalt via kort\n1 × Batteri\nTotalt: 1000 kr\nNamn: Kund\nE-post: kund.order@exempel.test\n";
$shop = $ingest('bf-shop', 'info@stores-emails.com', 'Order 90001', $shopBody, $lead(), '2026-10-08T08:00:00+02:00');
$road = $ingest('bf-road', 'kund.vag@exempel.test', 'Fråga om Malmövägen', "Namn: Kund\nE-post: kund.vag@exempel.test\nTelefon: 070-100 00 06\nAdress: Malmövägen 4, 177 30 Järfälla\nHej.\n", $lead(), '2026-10-09T08:00:00+02:00');
$quoted = $ingest('bf-quoted', 'kund.quote@exempel.test', 'Redan offert', $form('kund.quote@exempel.test', 'Testvägen 6', "Batteri.\n", '070-100 00 07'), $lead('batteri'), '2026-10-10T08:00:00+02:00');
jeab_inkorg_add_draft('bf-quoted', ['kind' => 'note', 'body' => 'Offert (Batteri) skickad'], 'test');
$young = $ingest('bf-young', 'kund.ung@exempel.test', 'Nytt lead', $form('kund.ung@exempel.test', 'Testvägen 7', "Batteri.\n", '070-100 00 08'), $lead('batteri'), date('c'));
$signed = $ingest('bf-signed', 'avtal@exempelnat.test', 'Signerad kopia av avtal 2026 Exempelnät', "Signerad kopia.\nAvtalet gäller anläggningar i Skåne.\n", [
    'type' => 'Information', 'action' => 'arkiv', 'priority' => 4, 'confidence' => 90,
    'intent' => 'information', 'offer' => '', 'product' => '', 'question' => '', 'source' => 'rule',
], '2026-10-11T08:00:00+02:00');
$signedLead = $ingest('bf-signed-lead', 'avtal@exempelnat.test', 'Signerad kopia av avtal 2026', "Signerad kopia av nätavtalet.\nOmråde: Skåne.\n", $lead(), '2026-10-12T08:00:00+02:00');
$owned = $ingest('bf-owned', 'kund.agare@exempel.test', 'Offert Malmö', "Namn: Kund\nE-post: kund.agare@exempel.test\nAdress: Storgatan 9, 211 22 Malmö\nSolceller.\n", $lead('sol'), '2026-10-13T08:00:00+02:00');
jeab_inkorg_thread_write('bf-owned', static function (array $row): array {
    $row['assignee'] = 'SamL';
    return $row;
});

bf(jeab_inkorg_thread_company(jeab_auto_thread_by_id('bf-malmo')) === 'malmo', 'Malmö i ett kundlead är bolaget malmo');
bf(jeab_inkorg_thread_company(jeab_auto_thread_by_id('bf-skane')) === 'malmo', 'Skåne i ett kundlead är bolaget malmo');
bf(jeab_inkorg_thread_company(jeab_auto_thread_by_id('bf-signed')) === 'jarfalla', 'signerad handling som nämner Skåne stannar i Järfälla');
bf(jeab_inkorg_thread_company(jeab_auto_thread_by_id('bf-signed-lead')) === 'jarfalla', 'felmärkt signerad handling byter inte bolag');
bf(jeab_inkorg_thread_company(jeab_auto_thread_by_id('bf-owned')) === 'jarfalla', 'lead med ägare byter inte bolag');
bf(jeab_inkorg_thread_company(jeab_auto_thread_by_id('bf-road')) === 'jarfalla', 'Malmövägen lämnar ärendet i Järfälla');
bf(jeab_inkorg_thread_company(jeab_auto_thread_by_id('bf-empty')) === 'jarfalla', 'formulär utan ort är Järfälla');
bf((jeab_inkorg_get((string) $supportIn['id'])['labels']['type'] ?? '') === 'Ny lead', 'gammal manuell etikett ligger kvar tills backfill');

$dry = jeab_inkorg_hook(['action' => 'inbox.lead_backfill']);
bf(($dry['ok'] ?? false) === true && ($dry['dry'] ?? 0) === 1 && ($dry['sent'] ?? 1) === 0, 'hooken är torr och skickar inget');
bf(!str_contains((string) json_encode($dry, JSON_UNESCAPED_UNICODE), '@'), 'svaret har ingen adress');
$by = [];
foreach ((array) ($dry['threads'] ?? []) as $row) {
    $by[(string) ($row['id'] ?? '')] = $row;
}
bf(($by['bf-empty']['action'] ?? '') === 'reminder' && ($by['bf-empty']['owner'] ?? '') === 'Sam L', 'lead utan produkt får påminnelse till ansvarig');
bf(($by['bf-sol']['action'] ?? '') === 'reminder' && ($by['bf-sol']['owner'] ?? '') === 'Sam L', 'sol och batteri går till Sam L');
bf(($by['bf-dup-a']['action'] ?? '') === 'reminder' && ($by['bf-dup-a']['owner'] ?? '') === 'Roberto', 'första kopian får påminnelsen');
bf(($by['bf-dup-b']['action'] ?? '') === 'link' && ($by['bf-dup-b']['target'] ?? '') === 'bf-dup-a', 'andra kopian länkas till den första');
bf(($by['bf-support']['action'] ?? '') === 'support', 'supportformulär med Ny lead blir support');
bf(($by['bf-malmo']['action'] ?? '') === 'malmo' && ($by['bf-skane']['action'] ?? '') === 'malmo', 'Malmö och Skåne i kundleads rapporteras och får ingen påminnelse');
bf(($by['bf-signed']['action'] ?? '') === 'skipped' && ($by['bf-signed']['reason'] ?? '') === 'signerad handling', 'signerad handling listas som skipped');
bf(($by['bf-signed-lead']['action'] ?? '') === 'skipped' && ($by['bf-signed-lead']['reason'] ?? '') === 'signerad handling', 'Ny lead på en signerad handling listas som skipped');
bf(($by['bf-owned']['action'] ?? '') === 'skipped' && ($by['bf-owned']['reason'] ?? '') === 'har redan en ägare', 'ägare listas som skipped');
bf(($by['bf-shop']['action'] ?? '') === 'webshop' && ($by['bf-shop']['fortnox'] ?? '') === 'dry', 'betald order med Ny lead går vägen för butiksorder');
bf(($by['bf-road']['action'] ?? '') === 'reminder', 'Malmövägen är ett vanligt lead');
bf(!isset($by['bf-quoted']) && !isset($by['bf-young']), 'offert i ärendet och lead yngre än två timmar hoppas över');
bf(count(jeab_list_reminders(jeab_store(), true)) === 0 && (jeab_inkorg_get((string) $shop['id'])['labels']['type'] ?? '') === 'Ny lead', 'torrkörningen skriver inget');
bf(count($GLOBALS['_SENT']) === 0 && $GLOBALS['JEAB_MAILS'] === 0 && $GLOBALS['JEAB_FORTNOX_WRITES'] === 0, 'torrkörningen skickar inget och skriver inte Fortnox');

$again = jeab_inkorg_hook(['action' => 'inbox.lead_backfill']);
bf(($again['threads'] ?? []) === ($dry['threads'] ?? []) && $GLOBALS['JEAB_FORTNOX_WRITES'] === 0, 'en andra torrkörning skriver fortfarande inget');

$wetEmpty = jeab_inkorg_hook(['action' => 'inbox.lead_backfill', 'thread' => 'bf-empty', 'dry' => 0]);
$emptyRow = (array) (($wetEmpty['threads'] ?? [])[0] ?? []);
$emptyRem = null;
foreach (jeab_list_reminders(jeab_store(), true) as $rem) {
    if (is_array($rem) && (string) ($rem['thread_id'] ?? '') === 'bf-empty') {
        $emptyRem = $rem;
    }
}
$emptyNote = (string) ($emptyRem['note'] ?? '');
bf(($wetEmpty['sent'] ?? 1) === 0 && ($emptyRow['action'] ?? '') === 'reminder' && is_array($emptyRem), 'dry=0 skapar en påminnelse');
bf((string) ($emptyRem['thread_id'] ?? '') === 'bf-empty' && !str_contains($emptyNote, 'mail:') && !str_contains(strtolower($emptyNote), 'helpscout'), 'påminnelsen länkar till ärendet');
bf((string) (jeab_inkorg_thread_meta('bf-empty')['assignee'] ?? '') === 'SamL', 'ärendet utan produkt tilldelas ansvarig');
bf(count($GLOBALS['_SENT']) === 0 && $GLOBALS['JEAB_MAILS'] === 0, 'påminnelsen skickar inget till kunden');

$wetA = jeab_inkorg_hook(['action' => 'inbox.lead_backfill', 'thread' => 'bf-dup-a', 'dry' => 0]);
$wetB = jeab_inkorg_hook(['action' => 'inbox.lead_backfill', 'thread' => 'bf-dup-b', 'dry' => 0]);
$dupNotes = 0;
foreach (jeab_list_reminders(jeab_store(), true) as $rem) {
    $note = (string) ($rem['note'] ?? '');
    if ((string) ($rem['thread_id'] ?? '') === 'bf-dup-a') {
        $dupNotes++;
        bf(str_contains($note, 'id=bf-dup-b'), 'kopian läggs på samma påminnelse');
    }
}
bf(($wetA['threads'][0]['action'] ?? '') === 'reminder' && ($wetB['threads'][0]['action'] ?? '') === 'link' && $dupNotes === 1, 'bara en påminnelse för samma kund');
bf((string) (jeab_inkorg_thread_meta('bf-dup-b')['duplicate_of'] ?? '') === 'bf-dup-a', 'kopian pekar på det öppna ärendet');

$beforeSupport = count(jeab_list_reminders(jeab_store(), true));
$wetSupport = jeab_inkorg_hook(['action' => 'inbox.lead_backfill', 'thread' => 'bf-support', 'dry' => 0]);
$supportNotes = '';
$supportThread = jeab_auto_thread_by_id('bf-support');
foreach ((array) ($supportThread['drafts'] ?? []) as $d) {
    if (is_array($d) && ($d['kind'] ?? '') === 'note') {
        $supportNotes .= (string) ($d['body'] ?? '') . "\n";
    }
}
bf(($wetSupport['threads'][0]['action'] ?? '') === 'support' && ($wetSupport['sent'] ?? 1) === 0, 'support skriver ingen kundtext');
bf((jeab_inkorg_get((string) $supportIn['id'])['labels']['type'] ?? '') === 'Support', 'Ny lead blir Support');
bf(str_contains($supportNotes, 'Ingen tidigare order hittad') || str_contains($supportNotes, 'Tidigare:'), 'historiken antecknas');
bf((string) (jeab_inkorg_thread_meta('bf-support')['assignee'] ?? '') === 'Roberto', 'batteri-support går till Roberto');
bf(count(jeab_list_reminders(jeab_store(), true)) === $beforeSupport, 'support skapar ingen påminnelse');

$wetShop = jeab_inkorg_hook(['action' => 'inbox.lead_backfill', 'thread' => 'bf-shop', 'dry' => 0]);
$shopRow = (array) (($wetShop['threads'] ?? [])[0] ?? []);
bf(($shopRow['action'] ?? '') === 'webshop' && (jeab_inkorg_get((string) $shop['id'])['labels']['type'] ?? '') === 'Order', 'ordern typas om till Order');
$after = $GLOBALS['JEAB_FORTNOX_WRITES'];
$wetShopAgain = jeab_inkorg_hook(['action' => 'inbox.lead_backfill', 'thread' => 'bf-shop', 'dry' => 0]);
bf(($shopRow['fortnox'] ?? '') === 'linked' && $GLOBALS['JEAB_FORTNOX_WRITES'] === $after && ($wetShopAgain['threads'] ?? []) === [], 'en andra körning rör inte den stängda ordern');
bf($GLOBALS['JEAB_MAILS'] === 0 && count($GLOBALS['_SENT']) === 0, 'butiksordern skickar inget');

$remBeforeMalmo = count(jeab_list_reminders(jeab_store(), true));
$wetMalmo = jeab_inkorg_hook(['action' => 'inbox.lead_backfill', 'thread' => 'bf-malmo', 'dry' => 0]);
$locked = false;
try {
    jeab_inkorg_thread_set('bf-malmo', 'closed', null);
} catch (JeabInkorgWriteException $e) {
    $locked = jeab_inkorg_error_status($e) === 403;
}
bf(($wetMalmo['threads'][0]['action'] ?? '') === 'malmo' && count(jeab_list_reminders(jeab_store(), true)) === $remBeforeMalmo && $locked, 'Malmö får ingen påminnelse och är skrivskyddad');

$fresh = $ingest('bf-fresh-support', 'kund.ny@exempel.test', 'Ny support', "Support till befintlig kund\nProdukt: solceller\n" . $form('kund.ny@exempel.test', 'Testvägen 8', '', '070-100 00 09'), $lead('sol'), $old);
bf((jeab_inkorg_get((string) $fresh['id'])['labels']['type'] ?? '') === 'Support', 'nytt supportformulär blir Support direkt');
$relabel = jeab_inkorg_relabel((string) $empty['id'], ['type' => 'Ny lead'], 'test');
bf(($relabel['labels']['type'] ?? '') === 'Ny lead', 'ett vanligt lead kan fortfarande märkas Ny lead');
$supportRelabel = jeab_inkorg_relabel((string) $fresh['id'], ['type' => 'Ny lead'], 'test');
bf(($supportRelabel['labels']['type'] ?? '') === 'Support', 'manuell Ny lead viker för supportformuläret');

$forward = $ingest('bf-forward', 'kund.fram@exempel.test', 'Utan produkt', $form('kund.fram@exempel.test', 'Testvägen 9', "Hej.\n", '070-100 00 10'), $lead(), $old);
$auto = jeab_auto_on_message((string) $forward['id']);
$forwardRem = 0;
foreach (jeab_list_reminders(jeab_store(), true) as $rem) {
    if (!is_array($rem)) {
        continue;
    }
    $linked = (string) ($rem['thread_id'] ?? '') === 'bf-forward'
        || str_contains((string) ($rem['note'] ?? ''), 'id=bf-forward');
    if ($linked) {
        $forwardRem++;
    }
}
bf(in_array((string) ($auto['action'] ?? ''), ['none', 'held', 'hold'], true) && (string) (jeab_inkorg_thread_meta('bf-forward')['assignee'] ?? '') === 'SamL' && $forwardRem === 1, 'nytt lead utan produkt hålls med en påminnelse');
bf(count($GLOBALS['_SENT']) === 0, 'inget utskick i hela testet');

echo "OK\n";
