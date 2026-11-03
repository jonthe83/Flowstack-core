<?php

declare(strict_types=1);

/** Kundtjänst: stängning, leads, omformulär, manuell produkt, dubblettpåminnelse. Påhittade namn. */

$tmp = sys_get_temp_dir() . '/jeab-cs-bugs-' . bin2hex(random_bytes(4));
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

function cs(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function cs_status(string $tid): string
{
    return (string) (jeab_inkorg_thread_meta($tid)['status'] ?? '');
}

function cs_touch_reminder(int $id, array $fields): void
{
    $store = jeab_store();
    if (!$store instanceof JeabPdoStore) {
        fwrite(STDERR, "FAIL sql-butik saknas\n");
        exit(1);
    }
    $pdo = $store->pdo();
    if (array_key_exists('deleted_at', $fields)) {
        $has = $pdo->query("SELECT 1 FROM pragma_table_info('reminders') WHERE name = 'deleted_at'")->fetchColumn();
        if (!$has) {
            $pdo->exec('ALTER TABLE reminders ADD COLUMN deleted_at TEXT');
        }
    }
    if (array_key_exists('list_id', $fields)) {
        $listId = (int) $fields['list_id'];
        $exists = $pdo->prepare('SELECT 1 FROM reminder_lists WHERE id = ?');
        $exists->execute([$listId]);
        if ($exists->fetchColumn() === false) {
            $pdo->prepare('INSERT INTO reminder_lists (id, name, created_at) VALUES (?, ?, ?)')->execute([
                $listId,
                'Lista ' . $listId,
                date('Y-m-d H:i:s'),
            ]);
        }
    }
    $sets = [];
    $vals = [];
    foreach ($fields as $key => $value) {
        if (!in_array($key, ['list_id', 'thread_id', 'deleted_at', 'completed_at'], true)) {
            continue;
        }
        $sets[] = $key . ' = ?';
        $vals[] = $value;
    }
    if ($sets === []) {
        return;
    }
    $vals[] = $id;
    $pdo->prepare('UPDATE reminders SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
}

function cs_lead(string $id, string $email, string $name, string $offer = 'batteri', string $when = '', int $confidence = 95, string $from = ''): array
{
    static $n = 60;
    $n++;
    $phone = sprintf('070-555 %02d %02d', intdiv($n, 100), $n % 100);
    $street = 'Testvägen ' . $n . ', 177 ' . sprintf('%02d', $n % 100) . ' Järfälla';
    $box = jeab_graph_mailboxes()['jarfalla'];
    $in = [
        'message_id' => $id . '@cs.test',
        'from' => $from !== '' ? $from : $email,
        'from_name' => $name,
        'to' => $box,
        'subject' => 'Fråga från ' . $name,
        'body_text' => "Namn: {$name}\nE-post: {$email}\nTelefon: {$phone}\nAdress: {$street}\nHej, jag vill ha batteri.\n",
        'source' => 'graph-jarfalla',
        'graph_mailbox' => $box,
        'thread_key' => 'cs-' . $id,
        'preset_labels' => [
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => $confidence,
            'intent' => 'kop', 'offer' => $offer, 'product' => $offer === '' ? 'okänd produkt' : $offer, 'question' => '',
        ],
    ];
    if ($when !== '') {
        $in['date'] = $when;
        $in['received_at'] = $when;
    }
    return jeab_inkorg_ingest($in);
}

function cs_quote_note(string $tid): void
{
    jeab_inkorg_thread_write($tid, static function (array $t): array {
        $t['drafts'] = [[
            'id' => 'd-q',
            'kind' => 'note',
            'body' => 'Offert (Batteri) skickad till kunden.',
            'state' => 'note',
            'created_at' => date('c'),
        ]];
        return $t;
    });
}

cs(jeab_inkorg_close_quoted_text('') === 'ingen träff att stänga på', 'tom orsak blir en konkret text');
cs(!str_contains(jeab_inkorg_close_quoted_text(''), 'okänd orsak'), 'okänd orsak kommer inte tillbaka');

$siri = cs_lead('siri', 'siri.dal@gmail.com', 'Siri Dal', 'batteri');
$siriTid = (string) $siri['thread_id'];
cs_quote_note($siriTid);
$siriRem = jeab_create_reminder(jeab_store(), 'Siri Dal uppföljning', jeab_today_start(), null, null, [
    'customer' => 'Siri Dal',
    'email' => 'siri.dal@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'mail: ' . jeab_lead_mail_url($siriTid),
]);
$siriId = (int) $siriRem['id'];
$linked = jeab_inkorg_hook(['action' => 'inbox.link_reminder', 'thread_id' => $siriTid, 'reminder_id' => $siriId, 'dry' => 0]);
$siriTitle = (string) (jeab_store()->getReminder($siriId)['title'] ?? '');
cs(($linked['reason'] ?? '') === 'redan länkad' && !str_contains($siriTitle, 'Batteri'), 'mail-länk utan produktord är redan länkad');
jeab_inkorg_thread_write($siriTid, static function (array $t): array {
    $t['status'] = 'open';
    unset($t['hook_closed']);
    return $t;
});
$siriClose = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $siriTid, 'dry' => 0]);
cs(cs_status($siriTid) === 'closed' && ($siriClose['reason'] ?? '') === 'offert och påminnelse', 'samma länk räcker för att stänga');
cs(!str_contains((string) json_encode($siriClose, JSON_UNESCAPED_UNICODE), 'okänd orsak'), 'stängningen säger inte okänd orsak');

$nils = cs_lead('nils', 'nils.ek@gmail.com', 'Nils Ek', 'batteri');
$nilsTid = (string) $nils['thread_id'];
cs_quote_note($nilsTid);
$nilsRem = jeab_create_reminder(jeab_store(), 'Nils Ek uppföljning', jeab_today_start(), null, null, [
    'customer' => 'Nils Ek',
    'email' => 'nils.ek@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'Ring.',
]);
cs_touch_reminder((int) $nilsRem['id'], ['list_id' => 2, 'thread_id' => $nilsTid]);
$nilsLink = jeab_inkorg_hook(['action' => 'inbox.link_reminder', 'thread_id' => $nilsTid, 'reminder_id' => (int) $nilsRem['id']]);
cs(($nilsLink['reason'] ?? '') === 'redan länkad', 'thread_id på en annan lista är redan länkad');
jeab_inkorg_thread_write($nilsTid, static function (array $t): array {
    $t['status'] = 'open';
    unset($t['hook_closed']);
    return $t;
});
$nilsClose = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $nilsTid, 'dry' => 0]);
cs(cs_status($nilsTid) === 'closed' && ($nilsClose['reason'] ?? '') === 'offert och påminnelse', 'stängning ser thread_id på annan lista');

$bo = cs_lead('bo', 'bo.holm@gmail.com', 'Bo Holm', 'batteri');
$boTid = (string) $bo['thread_id'];
cs_quote_note($boTid);
$boRem = jeab_create_reminder(jeab_store(), 'Bo Holm uppföljning', jeab_today_start(), null, null, [
    'customer' => 'Bo Holm',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'Ring om offerten.',
]);
jeab_store()->saveReminder((int) $boRem['id'], ['contact_id' => 'kont-bo']);
jeab_inkorg_thread_write($boTid, static function (array $t): array {
    $t['contact_id'] = 'kont-bo';
    $t['status'] = 'open';
    unset($t['hook_closed']);
    return $t;
});
$boClose = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $boTid, 'dry' => 0]);
cs(cs_status($boTid) === 'closed' && ($boClose['reason'] ?? '') === 'offert och påminnelse', 'kontakt utan produktord räknas');

$vide = cs_lead('vide', 'vide.holm@gmail.com', 'Vide Holm', 'batteri');
$videTid = (string) $vide['thread_id'];
cs_quote_note($videTid);
$videClose = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $videTid]);
cs(cs_status($videTid) === 'open' && ($videClose['reason'] ?? '') === 'offert utan påminnelse', 'offert utan påminnelse är ett eget skäl');

$park = cs_lead('park', 'park.lind@gmail.com', 'Park Lind', 'batteri');
$parkTid = (string) $park['thread_id'];
jeab_inkorg_thread_write($parkTid, static function (array $t): array {
    $t['status'] = 'waiting_customer';
    return $t;
});
$parkClose = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $parkTid]);
if (cs_status($parkTid) !== 'waiting_customer' || str_contains((string) json_encode($parkClose), 'okänd orsak')) {
    fwrite(STDERR, json_encode($parkClose, JSON_UNESCAPED_UNICODE) . "\n");
}
cs(cs_status($parkTid) === 'waiting_customer' && ($parkClose['reason'] ?? '') !== '' && ($parkClose['reason'] ?? '') !== 'offert och påminnelse' && !str_contains((string) json_encode($parkClose), 'okänd orsak'), 'parkerat utan offert stängs inte och skälet är konkret');

$oldWhen = date('c', time() - 10 * 86400);
$old = cs_lead('old', 'old.quist@gmail.com', 'Old Quist', 'batteri', $oldWhen);
$oldTid = (string) $old['thread_id'];
$fresh = cs_lead('fresh', 'fresh.quist@gmail.com', 'Fresh Quist', 'batteri');
$freshTid = (string) $fresh['thread_id'];
$shut = cs_lead('shut', 'shut.quist@gmail.com', 'Shut Quist', 'batteri');
$shutTid = (string) $shut['thread_id'];
jeab_inkorg_thread_write($shutTid, static function (array $t): array {
    $t['status'] = 'closed';
    return $t;
});
$beforeRem = count(jeab_store()->listReminders(true));
$dryLeads = jeab_inkorg_hook(['action' => 'inbox.leads']);
$dryIds = array_column((array) ($dryLeads['leads'] ?? []), 'thread');
cs(($dryLeads['dry'] ?? 0) === 1 && in_array($oldTid, $dryIds, true) && in_array($freshTid, $dryIds, true) && !in_array($shutTid, $dryIds, true), 'leads utan since tar med alla öppna');
cs(count(jeab_store()->listReminders(true)) === $beforeRem, 'dry=1 skriver inga påminnelser');
$since = jeab_inkorg_hook(['action' => 'inbox.leads', 'since' => date('c', time() - 86400)]);
$sinceIds = array_column((array) ($since['leads'] ?? []), 'thread');
cs(!in_array($oldTid, $sinceIds, true) && in_array($freshTid, $sinceIds, true), 'since begränsar listan');
$limited = jeab_inkorg_hook(['action' => 'inbox.leads', 'limit' => 1]);
cs(count((array) ($limited['leads'] ?? [])) === 1 && ($limited['limit'] ?? 0) === 1, 'limit tar en rad');
$badSince = jeab_inkorg_hook(['action' => 'inbox.leads', 'since' => 'inte-ett-datum']);
cs(($badSince['ok'] ?? true) === false, 'ogiltigt since avvisas');
$write = jeab_inkorg_hook(['action' => 'inbox.leads', 'thread' => $freshTid, 'dry' => 0]);
cs(($write['dry'] ?? 1) === 0 && (int) (($write['leads'][0]['reminder'] ?? 0)) > 0, 'dry=0 får skapa påminnelse');
$still = count(jeab_store()->listReminders(true));
jeab_inkorg_hook(['action' => 'inbox.leads', 'thread' => $oldTid, 'dry' => 1]);
cs(count(jeab_store()->listReminders(true)) === $still, 'dry=1 på ett gammalt lead skriver inte');

$moaOld = cs_lead('moa-old', 'moa.vret@gmail.com', 'Moa Vret', 'batteri');
$moaOldTid = (string) $moaOld['thread_id'];
jeab_inkorg_thread_write($moaOldTid, static function (array $t): array {
    $t['quote_sent'] = ['source' => 'anteckning', 'date' => date('c', time() - 10 * 86400), 'at' => date('c', time() - 10 * 86400)];
    $t['lead_customer'] = ['name' => 'Moa Vret', 'email' => 'moa.vret@gmail.com', 'phone' => '070-555 18 20', 'address' => 'Ekstigen 8, 177 50 Järfälla'];
    return $t;
});
$moaRem = jeab_create_reminder(jeab_store(), 'Moa Vret – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Moa Vret',
    'email' => 'moa.vret@gmail.com',
    'phone' => '070-555 18 20',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'mail: ' . jeab_lead_mail_url($moaOldTid),
]);
jeab_inkorg_thread_write($moaOldTid, static function (array $t): array {
    $t['status'] = 'closed';
    return $t;
});
$moaNew = cs_lead('moa-new', 'moa.vret@gmail.com', 'Moa Vret', 'batteri', '2026-10-02T09:00:00+02:00', 100, 'info@wix-forms.com');
$moaNewTid = (string) $moaNew['thread_id'];
$remBefore = count(jeab_store()->listReminders(true));
$GLOBALS['_SENT'] = [];
$moaAuto = jeab_auto_on_message((string) $moaNew['id']);
$moaNote = (string) (jeab_store()->getReminder((int) $moaRem['id'])['note'] ?? '');
cs(($moaAuto['action'] ?? '') === 'closed' && cs_status($moaNewTid) === 'closed', 'nytt formulär stängs mot befintlig offert');
cs(str_contains($moaNote, 'nytt formulär 2026-10-02') && str_contains($moaNote, $moaNewTid), 'påminnelsen får datum och ärendelänk');
cs(count(jeab_store()->listReminders(true)) === $remBefore && $GLOBALS['_SENT'] === [], 'ingen ny offert och ingen ny påminnelse');

$tyraContact = jeab_kontakt_find_or_create([
    'name' => 'Tyra Lund',
    'email' => 'tyra.lund@gmail.com',
    'phone' => '070-555 18 05',
    'address' => 'Björkallén 4, 177 41 Järfälla',
], false);
$tyraId = (string) ($tyraContact['id'] ?? '');
cs($tyraId !== '', 'kontakten för Tyra finns');
$tyraOld = cs_lead('tyra-old', 'tyra.lund@gmail.com', 'Tyra Lund', 'batteri');
$tyraOldTid = (string) $tyraOld['thread_id'];
jeab_inkorg_thread_write($tyraOldTid, static function (array $t) use ($tyraId): array {
    $t['contact_id'] = $tyraId;
    $t['quote_sent'] = ['source' => 'anteckning', 'date' => date('c', time() - 20 * 86400), 'at' => date('c', time() - 20 * 86400)];
    $t['lead_customer'] = ['name' => 'Tyra Lund', 'email' => 'tyra.lund@gmail.com', 'phone' => '070-555 18 05', 'address' => 'Björkallén 4, 177 41 Järfälla'];
    return $t;
});
jeab_create_reminder(jeab_store(), 'Tyra Lund – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Tyra Lund',
    'email' => 'tyra.lund@gmail.com',
    'phone' => '070-555 18 05',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'contact_id' => $tyraId,
    'note' => 'mail: ' . jeab_lead_mail_url($tyraOldTid),
]);
$tyraNew = jeab_inkorg_ingest([
    'message_id' => 'tyra-new@cs.test',
    'from' => 'info@wix-forms.com',
    'from_name' => 'Formulär',
    'to' => jeab_graph_mailboxes()['jarfalla'],
    'subject' => 'Nytt formulär Tyra',
    'body_text' => "Namn: Tyra Lund\nE-post: tyra.ny@gmail.com\nTelefon: 070-555 18 05\nAdress: Björkallén 4, 177 41 Järfälla\n",
    'source' => 'graph-jarfalla',
    'graph_mailbox' => jeab_graph_mailboxes()['jarfalla'],
    'thread_key' => 'cs-tyra-new',
    'date' => '2026-10-11T08:00:00+02:00',
    'preset_labels' => [
        'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 100,
        'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => '',
    ],
]);
$tyraAuto = jeab_auto_on_message((string) $tyraNew['id']);
cs(($tyraAuto['action'] ?? '') === 'closed' && cs_status((string) $tyraNew['thread_id']) === 'closed', 'samma telefon och adress via kontakter länkas');

$unaOld = cs_lead('una-old', 'una.berg@gmail.com', 'Una Berg', 'batteri');
$unaOldTid = (string) $unaOld['thread_id'];
jeab_inkorg_thread_write($unaOldTid, static function (array $t): array {
    $t['quote_sent'] = ['source' => 'anteckning', 'date' => date('c', time() - 5 * 86400), 'at' => date('c', time() - 5 * 86400)];
    return $t;
});
jeab_inkorg_thread_write($unaOldTid, static function (array $t): array {
    $t['status'] = 'closed';
    return $t;
});
$unaNew = cs_lead('una-new', 'una.berg@gmail.com', 'Una Berg', 'batteri', '2026-10-12T08:00:00+02:00', 100, 'info@wix-forms.com');
$unaAuto = jeab_auto_on_message((string) $unaNew['id']);
$unaWhy = (string) ($unaAuto['why'] ?? '');
$unaThread = jeab_auto_thread_by_id((string) $unaNew['thread_id']);
$unaNoted = false;
foreach ((array) ($unaThread['drafts'] ?? []) as $d) {
    if (is_array($d) && str_contains((string) ($d['body'] ?? ''), 'offert skickad de senaste 90 dagarna utan öppen påminnelse')) {
        $unaNoted = true;
    }
}
cs(($unaAuto['action'] ?? '') === 'hold' && $unaWhy === 'offert skickad de senaste 90 dagarna utan öppen påminnelse' && $unaNoted && cs_status((string) $unaNew['thread_id']) === 'closed', 'hållningen skapar påminnelse och lämnar inte formuläret öppet');

$ago = cs_lead('ago-old', 'ago.berg@gmail.com', 'Ago Berg', 'batteri');
jeab_inkorg_thread_write((string) $ago['thread_id'], static function (array $t): array {
    $t['quote_sent'] = ['source' => 'anteckning', 'date' => date('c', time() - 100 * 86400), 'at' => date('c', time() - 100 * 86400)];
    return $t;
});
jeab_create_reminder(jeab_store(), 'Ago Berg – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Ago Berg',
    'email' => 'ago.berg@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'äldre offert',
]);
jeab_inkorg_thread_write((string) $ago['thread_id'], static function (array $t): array {
    $t['status'] = 'closed';
    return $t;
});
$agoNew = cs_lead('ago-new', 'ago.berg@gmail.com', 'Ago Berg', 'batteri', '', 100, 'info@wix-forms.com');
$agoAuto = jeab_auto_on_message((string) $agoNew['id']);
cs(($agoAuto['action'] ?? '') === 'blocked_duplicate' && (int) ($agoAuto['sent'] ?? 1) === 0 && cs_status((string) $agoNew['thread_id']) === 'closed', 'äldre offert stoppar inte leadet; samma kund länkas till påminnelsen och stängs');

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a) {
    $GLOBALS['_SENT'][] = $a;
    $person = trim((string) ($a['opts']['person_email'] ?? ''));
    $from = $person !== '' ? $person : jeab_graph_mailboxes()[$a['company']];
    return ['ok' => true, 'from' => $from, 'graph_id' => 'g-cs', 'conversation_id' => 'CV', 'message_id' => 'sent-cs@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url): array {
    unset($method);
    if (str_contains($url, 'rut.malm')) {
        return ['code' => 500, 'body' => ['error' => ['message' => 'oläsbar']]];
    }
    if (str_contains($url, 'mailFolders/sentitems') || str_contains($url, '/messages')) {
        return ['code' => 200, 'body' => ['value' => []]];
    }
    return ['code' => 404, 'body' => []];
};
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode('%PDF-1.4 batteri')]);
jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode('%PDF-1.4 ladd')]);
jeab_auto_pdf_save(['key' => 'sol_jarfalla', 'data' => base64_encode('%PDF-1.4 sol')]);
jeab_notiser_save_body('offert-batteri-jarfalla', "Hej\n\nOffert batteri till kunden.", 'hook');

$unsure = cs_lead('unsure', 'unsure.dal@gmail.com', 'Unsure Dal', '', '', 40);
$unsureTid = (string) $unsure['thread_id'];
$remAtForce = count(jeab_store()->listReminders(true));
$dryForce = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $unsureTid, 'product' => 'elbilsladdare', 'force_product' => 1]);
$unsureThread = jeab_auto_thread_by_id($unsureTid);
$forcedNote = false;
foreach ((array) ($unsureThread['drafts'] ?? []) as $d) {
    if (is_array($d) && str_contains((string) ($d['body'] ?? ''), 'Produkten sattes manuellt')) {
        $forcedNote = true;
    }
}
cs(($dryForce['dry'] ?? 0) === 1 && ($dryForce['template'] ?? '') === 'Elbilsladdare' && !$forcedNote, 'torr force_product visar Elbilsladdare');
cs(count(jeab_store()->listReminders(true)) === $remAtForce && $GLOBALS['_SENT'] === [], 'torr force_product skriver inget');
$badProduct = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $unsureTid, 'product' => 'tak', 'force_product' => 1]);
cs(($badProduct['ok'] ?? true) === false && str_contains((string) ($badProduct['error'] ?? ''), 'elbilsladdare'), 'okänd produktkod avvisas');
$stillUnsure = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $unsureTid, 'dry' => 0]);
cs(($stillUnsure['why'] ?? '') === 'Grok är osäker' && $GLOBALS['_SENT'] === [], 'utan force_product hålls osäker Grok');

$liveForce = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $unsureTid, 'product' => 'batteri', 'force_product' => 1, 'dry' => 0]);
$unsureAfter = jeab_auto_thread_by_id($unsureTid);
$whoNote = false;
foreach ((array) ($unsureAfter['drafts'] ?? []) as $d) {
    if (is_array($d) && str_contains((string) ($d['body'] ?? ''), 'Produkten sattes manuellt till Batteri av hook')) {
        $whoNote = true;
    }
}
cs(($liveForce['ok'] ?? false) === true && count($GLOBALS['_SENT']) === 1 && $whoNote, 'force_product skickar och antecknar vem');

$rut = cs_lead('rut', 'rut.malm@gmail.com', 'Rut Malm', '', '', 40);
$sentBefore = count($GLOBALS['_SENT']);
$rutForce = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $rut['thread_id'], 'product' => 'batteri', 'force_product' => 1, 'dry' => 0]);
$rutNoted = false;
foreach ((array) (jeab_auto_thread_by_id((string) $rut['thread_id'])['drafts'] ?? []) as $d) {
    if (is_array($d) && str_contains((string) ($d['body'] ?? ''), 'Produkten sattes manuellt')) {
        $rutNoted = true;
    }
}
cs(($rutForce['action'] ?? '') === 'hold' && ($rutForce['why'] ?? '') !== '' && count($GLOBALS['_SENT']) === $sentBefore && !$rutNoted, 'oläsbar källa skickar inte trots force_product');

$mioOld = cs_lead('mio-old', 'mio.sant@gmail.com', 'Mio Sant', 'sol');
jeab_inkorg_thread_write((string) $mioOld['thread_id'], static function (array $t): array {
    $t['quote_sent'] = ['source' => 'anteckning', 'date' => date('c', time() - 3 * 86400), 'at' => date('c', time() - 3 * 86400)];
    return $t;
});
jeab_create_reminder(jeab_store(), 'Mio Sant – Solceller', jeab_today_start(), null, null, [
    'customer' => 'Mio Sant',
    'email' => 'mio.sant@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Sam L',
    'note' => 'mail: ' . jeab_lead_mail_url((string) $mioOld['thread_id']),
]);
$mioNew = cs_lead('mio-new', 'mio.sant@gmail.com', 'Mio Sant', '', '', 30);
$sentBefore = count($GLOBALS['_SENT']);
$mioForce = jeab_inkorg_hook([
    'action' => 'inbox.send_quote',
    'thread_id' => (string) $mioNew['thread_id'],
    'product' => 'sol',
    'force_product' => 1,
    'dry' => 0,
]);
cs(($mioForce['sent'] ?? 1) === 0 && ($mioForce['action'] ?? '') === 'closed' && count($GLOBALS['_SENT']) === $sentBefore, 'force_product respekterar dubbletten');

$trash = jeab_create_reminder(jeab_store(), 'Lo Falk – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Lo Falk',
    'phone' => '070-555 18 31',
    'email' => 'lo.falk@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Roberto',
]);
cs_touch_reminder((int) $trash['id'], ['deleted_at' => date('c')]);
for ($i = 0; $i < 30; $i++) {
    jeab_create_reminder(jeab_store(), 'Anteckning ' . $i, jeab_today_start(), null, null, [
        'customer' => 'Kund ' . $i,
        'company' => 'jarfalla',
        'who' => 'Roberto',
        'note' => 'rad ' . $i,
    ]);
}
$hidden = jeab_create_reminder(jeab_store(), 'Lo Falk – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Lo Falk',
    'phone' => '070-555 18 31',
    'email' => 'lo.falk@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'klar rad långt ner',
]);
cs_touch_reminder((int) $hidden['id'], ['list_id' => 2, 'completed_at' => date('c')]);
$again = jeab_reminder_create_action(jeab_store(), [
    'title' => 'Lo Falk – Batteri',
    'name' => 'Lo Falk',
    'phone' => '070-555 18 31',
    'email' => 'lo.falk@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'mail: ' . jeab_lead_mail_url('cs-lo-ny'),
]);
cs((int) $again['id'] === (int) $hidden['id'] && !empty($again['reused']), 'dubblett söker alla listor och klara rader');
cs(!str_contains((string) (jeab_store()->getReminder((int) $trash['id'])['note'] ?? ''), 'cs-lo-ny'), 'papperskorgen används inte');
$solar = jeab_reminder_create_action(jeab_store(), [
    'title' => 'Lo Falk – Solceller',
    'name' => 'Lo Falk',
    'phone' => '070-555 18 31',
    'company' => 'jarfalla',
    'who' => 'Sam L',
]);
cs((int) $solar['id'] !== (int) $hidden['id'], 'annan produkt blir en egen påminnelse');

$eva = jeab_create_reminder(jeab_store(), 'Eva Qvist – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Eva Qvist',
    'email' => 'eva.qvist@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Roberto',
]);
$evaAgain = jeab_create_reminder(jeab_store(), 'Eva Qvist – Batteri', jeab_today_start(), 'ny rad', null, [
    'customer' => 'Eva Qvist',
    'email' => 'eva.qvist@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Roberto',
]);
cs((int) $evaAgain['id'] === (int) $eva['id'], 'samma e-post och produkt länkas');

$contactRem = jeab_create_reminder(jeab_store(), 'Pia Holm – Elbilsladdare', jeab_today_start(), null, null, [
    'customer' => 'Pia Holm',
    'email' => 'pia.holm.gammal@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Elin',
]);
jeab_store()->saveReminder((int) $contactRem['id'], ['contact_id' => 'kont-pia']);
$piaAgain = jeab_create_reminder(jeab_store(), 'Pia Holm – Elbilsladdare', jeab_today_start(), null, null, [
    'customer' => 'Pia Holm',
    'email' => 'pia.holm.ny@gmail.com',
    'contact_id' => 'kont-pia',
    'company' => 'jarfalla',
    'who' => 'Elin',
]);
cs((int) $piaAgain['id'] === (int) $contactRem['id'], 'samma kontakt och produkt länkas');

$onlyTrash = jeab_create_reminder(jeab_store(), 'Ken Orr – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Ken Orr',
    'phone' => '070-555 18 44',
    'company' => 'jarfalla',
    'who' => 'Roberto',
]);
cs_touch_reminder((int) $onlyTrash['id'], ['deleted_at' => date('c')]);
$ken = jeab_create_reminder(jeab_store(), 'Ken Orr – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Ken Orr',
    'phone' => '070-555 18 44',
    'company' => 'jarfalla',
    'who' => 'Roberto',
]);
cs((int) $ken['id'] !== (int) $onlyTrash['id'], 'bara en borttagen påminnelse blockerar inte');

echo "OK  cs-bugs\n";
