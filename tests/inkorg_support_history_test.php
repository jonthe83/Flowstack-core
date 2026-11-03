<?php

declare(strict_types=1);

/** Support till befintlig kund: historik och tilldelning. Påhittade kunder. Inget utskick. */

$tmp = sys_get_temp_dir() . '/jeab-support-history-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function sh(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = function (array $a) {
    $GLOBALS['_SENT'][] = $a;
    return ['ok' => true, 'from' => 'saljare4@jarfalla.example.test', 'graph_id' => 'g-sh', 'conversation_id' => 'CV', 'message_id' => 'sent-sh@x'];
};
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_inkorg_helpscout_companies_save(['helpscout_companies' => []]);
jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode('%PDF-1.4 ladd')]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode('%PDF-1.4 batt')]);
jeab_auto_pdf_save(['key' => 'sol_jarfalla', 'data' => base64_encode('%PDF-1.4 sol')]);

function sh_notes(string $tid): string
{
    $bits = [];
    foreach ((array) (jeab_auto_thread_by_id($tid)['drafts'] ?? []) as $d) {
        if (is_array($d) && ($d['kind'] ?? '') === 'note') {
            $bits[] = (string) ($d['body'] ?? '');
        }
    }
    return implode("\n", $bits);
}

function sh_in(string $id, string $from, string $name, string $body, array $labels, string $company = 'jarfalla'): array
{
    $box = jeab_graph_mailboxes()[$company];
    return jeab_inkorg_ingest([
        'message_id' => $id . '@support.test',
        'from' => $from,
        'from_name' => $name,
        'to' => $box,
        'subject' => 'Meddelande från ' . $name,
        'body_text' => $body,
        'source' => $company === 'malmo' ? 'graph-malmo' : 'graph-jarfalla',
        'graph_mailbox' => $box,
        'preset_labels' => $labels,
        'thread_key' => 'support-' . $id,
    ]);
}

$store = jeab_store();
$remindersBefore = count(jeab_reminders_every($store));

$old = jeab_create_reminder($store, 'Mira Solberg Batteri', '2019-06-01 09:00:00', 'äldre batteri', null, [
    'customer' => 'Mira Solberg', 'email' => 'mira.solberg@gmail.com', 'address' => 'Ljungstigen 12, 177 61 Testköping',
    'company' => 'jarfalla', 'tags' => ['batteri'],
]);
$store->saveReminder((int) $old['id'], ['created_at' => '2019-06-01 09:00:00']);
$newer = jeab_create_reminder($store, 'Mira Solberg order', '2024-03-02 09:00:00', 'nyare order', null, [
    'customer' => 'Mira Solberg', 'email' => 'mira.solberg@gmail.com', 'company' => 'jarfalla',
]);
jeab_purchase_put([
    'reminder_id' => (int) $newer['id'],
    'kind' => 'laddbox',
    'email' => 'mira.solberg@gmail.com',
    'created_at' => '2024-03-02 11:00:00',
    'address' => 'Ljungstigen 12, 177 61 Testköping',
]);

$addrOnly = jeab_create_reminder($store, 'Husorder', '2022-01-15 09:00:00', 'adress', null, [
    'customer' => 'Annan Person', 'email' => '', 'company' => 'jarfalla',
]);
jeab_purchase_put([
    'reminder_id' => (int) $addrOnly['id'],
    'kind' => 'batteri',
    'email' => '',
    'address' => 'Videgränd 4, 177 62 Testköping',
    'created_at' => '2022-01-15 12:00:00',
]);

$contact = jeab_kontakt_find_or_create([
    'name' => 'Siri Dal',
    'email' => 'siri.dal@gmail.com',
    'street' => 'Hasselstigen 8',
    'postal_code' => '17763',
    'city' => 'Testköping',
]);
sh(is_array($contact) && (string) ($contact['id'] ?? '') !== '', 'kontakt för Siri Dal skapas');
$linked = jeab_create_reminder($store, 'Siri Dal order', '2020-08-08 09:00:00', 'kopplad', null, [
    'customer' => 'Siri Dal', 'email' => '', 'company' => 'jarfalla', 'contact_id' => (string) $contact['id'],
]);
jeab_purchase_put([
    'reminder_id' => (int) $linked['id'],
    'kind' => 'sol',
    'email' => '',
    'contact_id' => (string) $contact['id'],
    'created_at' => '2020-08-08 10:00:00',
]);

$labels = ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'confidence' => 90, 'lead' => 'kopt', 'question' => ''];

$none = sh_in('none', 'noa.vinter@gmail.com', 'Noa Vinter', "Vad gäller det: Support till befintlig kund\nProdukt: Elbilsladdare\nNamn: Noa Vinter\nE-post: noa.vinter@gmail.com\nAdress: Tallvägen 2, 177 64 Testköping\n", $labels);
$noneTid = (string) $none['thread_id'];
$noneRes = jeab_auto_on_message((string) $none['id']);
$noneAgain = jeab_auto_on_message((string) $none['id']);
$noneNotes = sh_notes($noneTid);
$noneMsg = jeab_inkorg_get((string) $none['id']);
sh(($noneRes['action'] ?? '') === 'support' && ($noneRes['sent'] ?? 1) === 0 && ($noneAgain['action'] ?? '') === 'support', 'support utan historik skickar inget');
sh($noneNotes === 'Ingen tidigare order hittad', 'saknad historik blir en intern rad');
sh(!str_contains($noneNotes, 'laddbox') && str_contains($noneNotes, 'Ingen tidigare order hittad'), 'noten säger inte laddbox');
sh((string) (jeab_inkorg_thread_meta($noneTid)['assignee'] ?? '') === 'Elin', 'Elbilsladdare tilldelas Elin');
sh(is_array($noneMsg) && ($noneMsg['labels']['type'] ?? '') === 'Support' && ($noneMsg['labels']['intent'] ?? '') === 'support', 'typen blir Support');
sh(!jeab_lead_is_lead(jeab_auto_thread_by_id($noneTid), (array) $noneMsg) && !jeab_inkorg_awaiting_quote(jeab_auto_thread_by_id($noneTid)), 'support är varken lead eller offert');
sh(substr_count($noneNotes, 'Ingen tidigare order hittad') === 1, 'noten skrivs en gång');

$mira = sh_in('mira', 'mira.solberg@gmail.com', 'Mira Solberg', "Vad gäller det: Support till befintlig kund\nNamn: Mira Solberg\nE-post: mira.solberg@gmail.com\nAdress: Ljungstigen 12, 177 61 Testköping\n", [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => '', 'product' => '', 'confidence' => 80, 'question' => '',
]);
$miraRes = jeab_auto_on_message((string) $mira['id']);
$miraNotes = sh_notes((string) $mira['thread_id']);
$miraLines = explode("\n", $miraNotes);
sh(($miraRes['sent'] ?? 1) === 0 && $miraLines === ['Tidigare: Elbilsladdare 2024', 'Tidigare: Batteri 2019'], 'alla träffar, senaste först, Elbilsladdare');
sh((string) (jeab_inkorg_thread_meta((string) $mira['thread_id'])['assignee'] ?? '') === 'Elin', 'utan produkt i formuläret används senaste träffen');
sh(!str_contains($miraNotes, 'laddbox'), 'historiken skriver Elbilsladdare');

$tyra = sh_in('tyra', 'tyra.ek@gmail.com', 'Tyra Ek', "Vad gäller det: Support till befintlig kund\nProdukt: batteri\nNamn: Tyra Ek\nE-post: tyra.ek@gmail.com\nAdress: Videgränd 4, 177 62 Testköping\n", [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => '', 'product' => '', 'confidence' => 80, 'question' => '',
]);
$tyraRes = jeab_auto_on_message((string) $tyra['id']);
sh(($tyraRes['sent'] ?? 1) === 0 && sh_notes((string) $tyra['thread_id']) === 'Tidigare: Batteri 2022' && (string) (jeab_inkorg_thread_meta((string) $tyra['thread_id'])['assignee'] ?? '') === 'Roberto', 'adress träffar ordern och batteri går till Roberto');

$siri = sh_in('siri', 'siri.dal@gmail.com', 'Siri Dal', "Vad gäller det: Support till befintlig kund\nNamn: Siri Dal\nE-post: siri.dal@gmail.com\n", [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => '', 'product' => '', 'confidence' => 80, 'question' => '',
]);
$siriRes = jeab_auto_on_message((string) $siri['id']);
sh(($siriRes['sent'] ?? 1) === 0 && sh_notes((string) $siri['thread_id']) === 'Tidigare: Solceller 2020' && (string) (jeab_inkorg_thread_meta((string) $siri['thread_id'])['assignee'] ?? '') === 'SamL', 'kontaktens order ger sol och Sam L');

$esra = sh_in('esra', 'esra.holmberg@gmail.com', 'Esra Holmberg', "Vad gäller det: Support till befintlig kund\nProdukt: solceller\nNamn: Esra Holmberg\nE-post: esra.holmberg@gmail.com\n", [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => '', 'product' => '', 'confidence' => 80, 'question' => '',
], 'malmo');
$esraRes = jeab_auto_on_message((string) $esra['id']);
sh(($esraRes['sent'] ?? 1) === 0 && sh_notes((string) $esra['thread_id']) === 'Ingen tidigare order hittad' && (string) (jeab_inkorg_thread_meta((string) $esra['thread_id'])['assignee'] ?? '') === 'SamW', 'Malmö sol tilldelas Sam W');

$kimma = sh_in('kimma', 'kimma.berg@gmail.com', 'Kimma Berg', "Vad gäller det: Support till befintlig kund\nProdukt: Elbilsladdare\nNamn: Kimma Berg\nE-post: kimma.berg@gmail.com\n", [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'laddbox', 'product' => 'laddbox', 'confidence' => 90, 'question' => '',
], 'malmo');
$kimmaRes = jeab_auto_on_message((string) $kimma['id']);
sh(($kimmaRes['sent'] ?? 1) === 0 && (string) (jeab_inkorg_thread_meta((string) $kimma['thread_id'])['assignee'] ?? '') === 'Kim', 'Malmö Elbilsladdare tilldelas Kim');

sh($GLOBALS['_SENT'] === [], 'inget mejl skickas');
sh(count(jeab_reminders_every($store)) === $remindersBefore + 4, 'support skapar ingen påminnelse');

$plain = ['labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri']];
sh(jeab_lead_is_lead(['id' => 'x'], ['body' => "Namn: Nils Plain\n", 'from' => 'nils.plain@gmail.com', 'labels' => $plain['labels']]), 'ett vanligt lead är fortfarande ett lead');

$settingsPath = jeab_inkorg_dir() . '/settings.json';
$before = (string) file_get_contents($settingsPath);
$off = jeab_inkorg_hook(['action' => 'inbox.status']);
sh(!empty($off['ok']) && $off['rebook_enabled'] === false && (string) file_get_contents($settingsPath) === $before, 'inbox.status läser avstängd ombokning utan att skriva');
jeab_rebook_settings_save(['rebook_enabled' => 1]);
$on = jeab_inkorg_hook(['action' => 'inbox.status']);
$mid = (string) file_get_contents($settingsPath);
sh(!empty($on['ok']) && $on['rebook_enabled'] === true && (string) file_get_contents($settingsPath) === $mid, 'inbox.status läser påslagen ombokning utan att skriva');
jeab_rebook_settings_save(['rebook_enabled' => 0]);
$back = jeab_inkorg_hook(['action' => 'inbox.status']);
sh(!empty($back['ok']) && $back['rebook_enabled'] === false, 'inbox.status läser av igen');

echo "support history ok\n";
