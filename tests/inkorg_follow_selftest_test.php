<?php

declare(strict_types=1);

/** Självtestets kvittens går bara till deliver_to, även när leadtexten har en annan adress. */

$tmp = sys_get_temp_dir() . '/jeab-follow-self-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function fs(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$own = 'demo.person@example.invalid';
$decoy = 'nora.decoy@gmail.com';
$lead = "Namn:\n\nNora Decoy\n\nAdress:\n\nDecoyvägen 4, 176 70 Järfälla\n\nTelefon:\n\n070-555 11 22\n\nE-post:\n\n{$decoy}\n";

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = function (array $a) {
    $GLOBALS['_SENT'][] = $a;
    $person = trim((string) ($a['opts']['person_email'] ?? ''));
    $from = $person !== '' ? $person : jeab_graph_mailboxes()[$a['company']];
    return ['ok' => true, 'from' => $from, 'graph_id' => 'g-self', 'conversation_id' => 'CVS', 'message_id' => 'sent-self-' . count($GLOBALS['_SENT']) . '@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn(): array => ['code' => 200, 'body' => ['value' => []]];

jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode("%PDF-1.4 self")]);
jeab_notiser_save_body('offert-batteri-jarfalla', "Hej\n\nOffert batteri.", 'test');
jeab_notiser_save_body('offert-batteri-malmo', "Hej\n\nOffert batteri Malmö.", 'test');
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'follow_receipt_enabled' => 1,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);

$denied = [
    'testkund@example.com' => 'testkund',
    'leads@leadkit.com' => 'leads@leadkit',
    'support@leadkit.com' => 'support@leadkit',
];
foreach ($denied as $addr => $label) {
    $before = count($GLOBALS['_SENT']);
    $badFrom = jeab_auto_selftest([
        'from' => $addr,
        'deliver_to' => $own,
        'text' => $lead,
    ]);
    fs(($badFrom['ok'] ?? true) === false
        && str_contains((string) ($badFrom['error'] ?? ''), 'avsändaren')
        && count($GLOBALS['_SENT']) === $before, $label . ' är inte en tillåten avsändare');
}

$before = count($GLOBALS['_SENT']);
$first = jeab_auto_selftest([
    'deliver_to' => $own,
    'from' => 'info@wix-forms.com',
    'text' => $lead,
]);
$quote = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
fs(($first['ok'] ?? false) === true && ($first['auto']['action'] ?? '') === 'quoted' && count($GLOBALS['_SENT']) === $before + 1, 'första självtestet skickar offert');
fs(($quote['to'] ?? '') === $own && !str_contains((string) ($quote['to'] ?? ''), $decoy), 'offerten går till deliver_to och inte till adressen i leadtexten');

$tid = (string) ($first['thread'] ?? '');
fs(str_starts_with($tid, 'autotest-'), 'ärendet är ett autotest');

$foreign = jeab_auto_selftest(['reply_to' => 'inkorg-vanligt', 'deliver_to' => $own, 'text' => $lead]);
fs(($foreign['ok'] ?? true) === false && str_contains((string) ($foreign['error'] ?? ''), 'autotest'), 'reply_to utanför autotest nekas');
$missing = jeab_auto_selftest(['reply_to' => 'autotest-saknas', 'deliver_to' => $own, 'text' => $lead]);
fs(($missing['ok'] ?? true) === false && count($GLOBALS['_SENT']) === $before + 1, 'okänt autotest-ärende nekas');
$noDeliver = jeab_auto_selftest(['reply_to' => $tid, 'text' => $lead]);
fs(($noDeliver['ok'] ?? true) === false && str_contains((string) ($noDeliver['error'] ?? ''), 'deliver_to'), 'reply_to utan deliver_to nekas');
$other = jeab_auto_selftest(['reply_to' => $tid, 'deliver_to' => 'kund@gmail.com', 'text' => $lead]);
fs(($other['ok'] ?? true) === false && count($GLOBALS['_SENT']) === $before + 1, 'reply_to till en annan adress nekas');

$before = count($GLOBALS['_SENT']);
$second = jeab_auto_selftest([
    'reply_to' => $tid,
    'deliver_to' => $own,
    'from' => 'info@wix-forms.com',
    'text' => $lead . "\nMeddelande:\nKan ni ringa ikväll?\n",
    'question' => 'Kan ni ringa ikväll?',
]);
$receipt = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
fs(($second['ok'] ?? false) === true && ($second['thread'] ?? '') === $tid, 'andra meddelandet ligger i samma autotest-ärende');
fs(($second['auto']['action'] ?? '') === 'follow' && ($second['auto']['quoted'] ?? true) === false && ($second['auto']['receipt'] ?? 0) === 1, 'svaret kör kvittensvägen och inte en ny offert');
fs(count($GLOBALS['_SENT']) === $before + 1 && ($receipt['to'] ?? '') === $own, 'kvittensen går bara till deliver_to');
$tos = [];
foreach ($GLOBALS['_SENT'] as $sentRow) {
    $tos[] = (string) ($sentRow['to'] ?? '');
    foreach ((array) ($sentRow['cc'] ?? []) as $cc) {
        $tos[] = (string) $cc;
    }
}
fs(!in_array($decoy, $tos, true) && !str_contains((string) ($receipt['body'] ?? ''), $decoy), 'varken mottagare eller kvittensen är adressen i leadtexten');
fs(str_contains((string) ($receipt['body'] ?? ''), 'Tack för ditt svar') && ($receipt['opts']['person_email'] ?? '') === 'saljare3@jarfalla.example.test', 'kvittensen kommer från påminnelsens ägare');
fs(!isset($GLOBALS['_JEAB_AUTO_DELIVER_TO']), 'deliver_to läcker inte efter självtestet');

$elin = jeab_create_reminder(jeab_store(), 'Elin Kund – Elbilsladdare', jeab_today_start(), null, null, [
    'customer' => 'Elin Kund',
    'email' => $own,
    'company' => 'jarfalla',
    'who' => 'Elin',
    'note' => 'riktig påminnelse på självtestets gmail',
]);
jeab_toggle_reminder(jeab_store(), (int) $elin['id'], true);
$before = count($GLOBALS['_SENT']);
$fresh = jeab_auto_selftest([
    'deliver_to' => $own,
    'from' => $own,
    'text' => 'Jag vill köpa ett batteri till villan.',
]);
$elinNow = jeab_get_reminder(jeab_store(), (int) $elin['id']);
// Personalens gmail är egen post. Samma deliver_to har redan fått batteriofferten
// i det första självtestet, så en andra offert av samma produkt ska inte gå ut.
// Elins påminnelse har ingen offertanteckning och ska stå kvar stängd.
fs(
    ($fresh['ok'] ?? false) === true
    && str_starts_with((string) ($fresh['thread'] ?? ''), 'autotest-')
    && ($fresh['auto']['action'] ?? '') === 'historik'
    && ($fresh['auto']['why'] ?? '') === 'egen'
    && count($GLOBALS['_SENT']) === $before
    && is_array($elinNow)
    && !empty($elinNow['completed_at'])
    && !str_contains((string) ($elinNow['note'] ?? ''), 'Kunden svarade'),
    'självtest från personalens gmail är egen post och rör inte Elins påminnelse'
);
$before = count($GLOBALS['_SENT']);
$freshReply = jeab_auto_selftest([
    'reply_to' => $tid,
    'deliver_to' => $own,
    'from' => 'info@wix-forms.com',
    'text' => "Meddelande:\nKan ni ringa ikväll?\n",
    'question' => 'Kan ni ringa ikväll?',
]);
$freshReceipt = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
$elinAfter = jeab_get_reminder(jeab_store(), (int) $elin['id']);
fs(
    ($freshReply['thread'] ?? '') === $tid
    && ($freshReply['auto']['action'] ?? '') === 'follow'
    && (int) ($freshReply['auto']['reminder'] ?? 0) !== (int) $elin['id']
    && ($freshReply['auto']['owner'] ?? '') === 'Roberto'
    && (int) ($freshReply['auto']['receipt'] ?? 1) === 0
    && count($GLOBALS['_SENT']) === $before
    && is_array($elinAfter)
    && !empty($elinAfter['completed_at'])
    && !str_contains((string) ($elinAfter['note'] ?? ''), 'Kunden svarade'),
    'reply_to matchar bara autotest-ärendet och rör inte Elin'
);
$followLines = 0;
foreach (jeab_list_reminders(jeab_store(), true) as $remRow) {
    if (is_array($remRow) && str_contains((string) ($remRow['note'] ?? ''), 'Kunden svarade')) {
        $followLines++;
    }
}
fs($followLines === 0, 'självtestet lägger ingen följningsrad och öppnar ingen påminnelse');

jeab_auto_pdf_save(['key' => 'batteri_malmo', 'data' => base64_encode('%PDF-1.4 malmo-b')]);
jeab_auto_pdf_save(['key' => 'laddbox_malmo', 'data' => base64_encode('%PDF-1.4 malmo-l')]);
jeab_malmo_hs_settings_save(['malmo_hs_sync' => '1', 'malmo_hs_close' => '1']);
$malmoLead = "Namn:\n\nMaja Holm\n\nAdress:\n\nStorgatan 1, 211 20 Malmö\n\nTelefon:\n\n070-333 44 55\n\nE-post:\n\nmaja.holm.malmo@gmail.com\n\nMeddelande:\nJag vill ha ett batteri.\n";
$before = count($GLOBALS['_SENT']);
$malmo = jeab_auto_selftest([
    'company' => 'malmo',
    'deliver_to' => $own,
    'from' => 'info@wix-forms.com',
    'text' => $malmoLead,
]);
fs(
    ($malmo['auto']['action'] ?? '') === 'hold'
    && ($malmo['auto']['why'] ?? '') === 'redan skickad'
    && count($GLOBALS['_SENT']) === $before,
    'Malmö-självtest skickar inte en andra batterioffert till samma adress'
);

jeab_malmo_hs_settings_save(['malmo_hs_sync' => '0', 'malmo_hs_close' => '0']);
$before = count($GLOBALS['_SENT']);
$malmoOff = jeab_auto_selftest([
    'company' => 'malmo',
    'deliver_to' => $own,
    'from' => 'info@wix-forms.com',
    'text' => str_replace('maja.holm.malmo@gmail.com', 'maja.av.malmo@gmail.com', $malmoLead),
]);
fs(
    ($malmoOff['auto']['action'] ?? '') === 'historik'
    && ($malmoOff['auto']['why'] ?? '') === 'offert de senaste 6 månaderna'
    && count($GLOBALS['_SENT']) === $before,
    'avstängd Malmö-synk skickar ingen ny batterioffert till samma adress'
);

jeab_malmo_hs_settings_save(['malmo_hs_sync' => '1', 'malmo_hs_close' => '1']);
$before = count($GLOBALS['_SENT']);
$sol = jeab_auto_selftest([
    'company' => 'malmo',
    'mall' => 'A',
    'deliver_to' => $own,
    'name' => 'Sol Malmö',
    'email' => 'sol.malmo.self@gmail.com',
    'address' => 'Solgatan 2, 211 20 Malmö',
]);
fs(
    ($sol['auto']['action'] ?? '') !== 'quoted'
    && count($GLOBALS['_SENT']) === $before
    && (($sol['auto']['why'] ?? '') === 'bolaget hanteras i Help Scout' || ($sol['auto']['action'] ?? '') === 'hold'),
    'Malmö-sol stannar i Help Scout även när synken är på'
);
$before = count($GLOBALS['_SENT']);
$plain = jeab_auto_selftest([
    'company' => 'malmo',
    'from' => 'info@wix-forms.com',
    'subject' => 'Hej',
    'text' => 'Hej, ingen produkt.',
]);
fs(($plain['auto']['action'] ?? '') !== 'quoted' && count($GLOBALS['_SENT']) === $before, 'Malmö utan köpintresse skickar ingen offert');

$kimIn = jeab_inkorg_ingest([
    'message_id' => 'mhs-kim-ladd@jeab.test',
    'from' => 'kim.kund.ladd@gmail.com',
    'from_name' => 'Kim Kund',
    'to' => 'kontakt@malmo.example.test',
    'subject' => 'Elbilsladdare',
    'body_text' => 'Jag vill ha en laddbox.',
    'source' => 'malmo_hs',
    'thread_key' => 'mhs-kim-ladd',
    'company' => 'malmo',
    'graph_mailbox' => 'kontakt@malmo.example.test',
    'preset_labels' => [
        'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 100,
        'intent' => 'kop', 'offer' => 'laddbox', 'product' => 'elbilsladdare', 'question' => '',
    ],
]);
jeab_inkorg_thread_write((string) $kimIn['thread_id'], static function (array $t): array {
    $t['malmo_hs_kim'] = true;
    $t['company'] = 'malmo';
    return $t;
});
$before = count($GLOBALS['_SENT']);
$kimAuto = jeab_auto_on_message((string) $kimIn['id'], true);
$kimSend = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
fs(
    ($kimAuto['action'] ?? '') === 'quoted'
    && count($GLOBALS['_SENT']) === $before + 1
    && ($kimSend['opts']['person_email'] ?? '') === 'saljare.syd@malmo.example.test',
    'kopierat Kim-ärende med elbilsladdare får Kims offert'
);
$solIn = jeab_inkorg_ingest([
    'message_id' => 'mhs-sol@jeab.test',
    'from' => 'sol.kund.hs@gmail.com',
    'from_name' => 'Sol Kund',
    'to' => 'kontakt@malmo.example.test',
    'subject' => 'Solceller',
    'body_text' => 'Jag vill ha solceller i Malmö.',
    'source' => 'malmo_hs',
    'thread_key' => 'mhs-sol',
    'company' => 'malmo',
    'graph_mailbox' => 'kontakt@malmo.example.test',
    'preset_labels' => [
        'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 100,
        'intent' => 'kop', 'offer' => 'sol', 'product' => 'solceller', 'question' => '',
    ],
]);
$before = count($GLOBALS['_SENT']);
$solAuto = jeab_auto_on_message((string) $solIn['id'], true);
fs(
    ($solAuto['action'] ?? '') === 'hold'
    && ($solAuto['why'] ?? '') === 'bolaget hanteras i Help Scout'
    && count($GLOBALS['_SENT']) === $before,
    'kopierad Malmö-sol stannar i Help Scout'
);

echo "inkorg follow selftest: ok\n";
