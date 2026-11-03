<?php

declare(strict_types=1);

/** Retroaktiv offert. Inget riktigt mejl: Graph är en attrapp. */

$tmp = sys_get_temp_dir() . '/jeab-quote-backfill-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function qb(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$rules = implode("\n", jeab_auto_rule_lines());
qb(str_contains($rules, 'inbox.enable_auto_quote') && str_contains($rules, 'inbox.quote_backfill') && str_contains($rules, 'inbox.quote_test'), 'reglerna nämner de tre hookarna');

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $files = [];
    foreach ((array) ($a['attachments'] ?? []) as $att) {
        if (is_array($att) && (string) ($att['filename'] ?? '') !== '') {
            $files[] = (string) $att['filename'];
        }
    }
    $GLOBALS['_SENT'][] = [
        'to' => (string) ($a['to'] ?? ''),
        'subject' => (string) ($a['subject'] ?? ''),
        'files' => $files,
    ];

    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g' . count($GLOBALS['_SENT']), 'conversation_id' => 'c', 'message_id' => 'm' . count($GLOBALS['_SENT']) . '@x'];
};

jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'grok_decides' => 0,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'sol_jarfalla', 'data' => base64_encode('%PDF-1.4 sol')]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode('%PDF-1.4 batteri')]);
jeab_auto_pdf_save(['key' => 'batteri_malmo', 'data' => base64_encode('%PDF-1.4 batteri-malmo')]);
jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode('%PDF-1.4 ladd')]);
require __DIR__ . '/quote_notiser_seed.php';
jeab_test_seed_quote_notiser();

$dryOn = jeab_inkorg_hook(['action' => 'inbox.enable_auto_quote']);
qb(($dryOn['ok'] ?? false) === true && ($dryOn['dry'] ?? 0) === 1 && ($dryOn['would_enable'] ?? 0) === 1 && ($dryOn['auto_quote_enabled'] ?? true) === false && ($dryOn['sent'] ?? 1) === 0 && ($dryOn['would_send'] ?? true) === false, 'dry lämnar offertutskick av');
qb(jeab_auto_settings()['quote_enabled'] === false && jeab_auto_settings()['grok_decides'] === false, 'dry skriver inte settings');

$wetOn = jeab_inkorg_hook(['action' => 'inbox.enable_auto_quote', 'dry' => 0]);
qb(($wetOn['ok'] ?? false) === true && ($wetOn['auto_quote_enabled'] ?? false) === true && ($wetOn['changed'] ?? 0) === 1 && ($wetOn['sent'] ?? 1) === 0 && jeab_auto_settings()['quote_enabled'] === true && jeab_auto_settings()['grok_decides'] === false, 'dry=0 slår bara på offertutskick');
$againOn = jeab_inkorg_hook(['action' => 'inbox.enable_auto_quote', 'dry' => 0]);
qb(($againOn['changed'] ?? 1) === 0 && ($againOn['auto_quote_enabled'] ?? false) === true && ($againOn['sent'] ?? 1) === 0, 'andra körningen skriver inget');

$case = static function (string $product): array {
    return [
        'type' => 'Ny lead',
        'intent' => 'kopintresse',
        'kind' => 'atgard',
        'primary_product' => $product,
        'secondary_products' => [],
        'confidence' => ['primary_product' => 0.95, 'intent' => 0.95, 'type' => 0.95, 'kind' => 0.95],
    ];
};
$in = static function (string $id, string $from, string $name, string $subject, string $body, array $labels, string $when = ''): array {
    $row = [
        'message_id' => $id . '@quote-backfill.test',
        'from' => $from,
        'from_name' => $name,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => $subject,
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'preset_labels' => $labels,
    ];
    if ($when !== '') {
        $row['received_at'] = $when;
    }

    return jeab_inkorg_ingest($row);
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
$leadBody = static function (string $name, string $email, string $ort, string $phone = '070-111 22 33'): string {
    return "Namn: {$name}\nE-post: {$email}\nTelefon: {$phone}\nAdress: Testvägen 1, {$ort}\n";
};
$labels = static function (string $offer, array $case = [], string $lead = ''): array {
    $row = [
        'type' => 'Ny lead',
        'intent' => 'kop',
        'confidence' => 95,
        'needs_review' => false,
        'offer' => $offer,
        'product' => $offer,
        'case_class' => $case,
    ];
    if ($lead !== '') {
        $row['lead'] = $lead;
    }

    return $row;
};

$costinel = $in('costinel', 'costinel.badescu@gmail.com', 'Costinel Badescu', 'Ny lead: Costinel Badescu · Villa i Sollentuna', $leadBody('Costinel Badescu', 'costinel.badescu@gmail.com', 'Sollentuna', '070-100 00 01'), $labels('', $case('Sol')));
$andy = $in('andy', 'andy.madani@gmail.com', 'Andy Madani', 'Ny lead: Andy Madani · Villa i Upplands-Bro', $leadBody('Andy Madani', 'andy.madani@gmail.com', 'Upplands-Bro', '070-100 00 02'), $labels('sol', $case('Sol')));
$mattias = $in('mattias', 'mattias.haag@gmail.com', 'Mattias Haag', 'JEAB Batteri lead', $leadBody('Mattias Haag', 'mattias.haag@gmail.com', 'Järfälla', '070-100 00 03'), $labels('', $case('Batteri')));
$testLead = $in('testlead', 'test.kund@example.com', 'Test', 'JEAB Batteri lead', $leadBody('Test', 'test.kund@example.com', 'Järfälla', '070-100 00 04'), $labels('batteri', $case('Batteri')));
$info = $in('perific', 'news@perific.se', 'Perific', 'Nu skickas Perific One V3', 'Ingen åtgärd.', ['type' => 'Information', 'intent' => 'information', 'confidence' => 90, 'offer' => '']);
$staff = $in('staff', 'demo@example.test', 'Sam', 'Intern lead', $leadBody('Sam', 'demo@example.test', 'Järfälla', '070-100 00 05'), $labels('batteri'));
$supplier = $in('supplier', 'order@lev.example', 'Export Sales', 'Order', $leadBody('Export Sales', 'order@lev.example', 'Järfälla', '070-100 00 06'), $labels('batteri'));
$old = $in('old', 'gammal.kund@gmail.com', 'Gammal Kund', 'Ny lead: Gammal Kund · Villa i Solna', $leadBody('Gammal Kund', 'gammal.kund@gmail.com', 'Solna', '070-100 00 07'), $labels('batteri'), date('c', time() - 40 * 86400));
$kopt = $in('kopt', 'leads@leadkit.com', 'Leadkit', 'Ny lead: Kopt Sol · Villa i Bromma', $leadBody('Kopt Sol', 'kopt.sol@gmail.com', 'Bromma', '070-100 00 11'), $labels('sol', $case('Sol')));
$eget = $in('eget', 'info@wix-forms.com', 'Wix', 'Ny lead: Eget Sol · Villa i Sundbyberg', $leadBody('Eget Sol', 'eget.sol@gmail.com', 'Sundbyberg', '070-100 00 12'), $labels('sol', $case('Sol')));
$osaker = $in('osaker', 'info@wix-forms.com', 'Wix', 'Ny lead: Osaker Sol · Villa i Kista', $leadBody('Osaker Sol', 'osaker.sol@gmail.com', 'Kista', '070-100 00 13'), $labels('sol', $case('Sol'), 'kopt'));
foreach ([$costinel, $andy, $mattias] as $row) {
    jeab_inkorg_thread_set((string) $row['thread_id'], 'closed', null);
}

$plain = json_encode(jeab_inkorg_hook(['action' => 'inbox.quote_backfill']), JSON_UNESCAPED_UNICODE) ?: '';
qb(!str_contains($plain, 'costinel.badescu@gmail.com') && !str_contains($plain, 'andy.madani@gmail.com') && !str_contains($plain, 'mattias.haag@gmail.com'), 'dry-svaret har ingen fullständig adress');
$dry = jeab_inkorg_hook(['action' => 'inbox.quote_backfill']);
qb(($dry['ok'] ?? false) === true && ($dry['dry'] ?? 0) === 1 && ($dry['sent'] ?? 1) === 0 && ($dry['days'] ?? 0) === 30 && ($dry['changed'] ?? 1) === 0 && $GLOBALS['_SENT'] === [], 'dry skickar inget');
$byId = [];
foreach ((array) ($dry['rows'] ?? []) as $row) {
    $byId[(string) ($row['id'] ?? '')] = $row;
}
qb(!isset($byId[(string) $info['thread_id']]), 'information listas inte');
qb(!isset($byId[(string) $old['thread_id']]), 'lead äldre än 30 dagar listas inte');
$costinelRow = $byId[(string) $costinel['thread_id']] ?? [];
qb(($costinelRow['name'] ?? '') === 'Costinel Badescu' && ($costinelRow['product'] ?? '') === 'Sol' && ($costinelRow['ort'] ?? '') === 'Sollentuna' && ($costinelRow['status'] ?? '') === 'closed' && ($costinelRow['template'] ?? 0) === 1 && ($costinelRow['pdf'] ?? 0) === 1 && ($costinelRow['mall'] ?? '') === 'B' && ($costinelRow['action'] ?? '') === 'would_send' && ($costinelRow['would_send'] ?? false) === true && ($costinelRow['masked'] ?? '') === 'c***@g***.com', 'Costinel skulle få soloffert mall B: ' . json_encode($costinelRow, JSON_UNESCAPED_UNICODE));
qb(($byId[(string) $andy['thread_id']]['action'] ?? '') === 'would_send' && ($byId[(string) $andy['thread_id']]['mall'] ?? '') === 'B' && ($byId[(string) $andy['thread_id']]['ort'] ?? '') === 'Upplands-Bro', 'Andy är ett stängt eget sollead');
$koptRow = $byId[(string) $kopt['thread_id']] ?? [];
qb(($koptRow['mall'] ?? '') === 'A' && ($koptRow['action'] ?? '') === 'would_send' && ($koptRow['product'] ?? '') === 'Sol', 'köpt leadkit-lead får mall A: ' . json_encode($koptRow, JSON_UNESCAPED_UNICODE));
$egetRow = $byId[(string) $eget['thread_id']] ?? [];
qb(($egetRow['mall'] ?? '') === 'B' && ($egetRow['action'] ?? '') === 'would_send', 'Wix-lead får mall B: ' . json_encode($egetRow, JSON_UNESCAPED_UNICODE));
$osakerRow = $byId[(string) $osaker['thread_id']] ?? [];
qb(($osakerRow['action'] ?? '') === 'would_hold' && ($osakerRow['mall'] ?? 'x') === '' && str_contains((string) ($osakerRow['why'] ?? ''), 'osäker solmall') && ($osakerRow['would_send'] ?? true) === false, 'oklar solmall hålls i dry-run: ' . json_encode($osakerRow, JSON_UNESCAPED_UNICODE));
qb(($byId[(string) $mattias['thread_id']]['product'] ?? '') === 'Batteri' && ($byId[(string) $mattias['thread_id']]['action'] ?? '') === 'would_send', 'Mattias får batteri från case_class');
qb(($byId[(string) $testLead['thread_id']]['action'] ?? '') === 'skip' && ($byId[(string) $testLead['thread_id']]['why'] ?? '') === 'test' && ($thread((string) $testLead['thread_id'])['status'] ?? '') === 'open', 'test listas och lämnas öppet');
qb(($byId[(string) $staff['thread_id']]['why'] ?? '') === 'intern', 'egen personal hoppas över');
qb(($byId[(string) $supplier['thread_id']]['why'] ?? '') === 'leverantör', 'leverantör hoppas över');
qb(($thread((string) $costinel['thread_id'])['status'] ?? '') === 'closed' && $rems() === 0, 'dry ändrar inte stängda lead och skapar ingen påminnelse');

$remBefore = $rems();
$sentBefore = count($GLOBALS['_SENT']);
$one = jeab_inkorg_hook(['action' => 'inbox.quote_backfill', 'dry' => 0, 'thread' => (string) $mattias['thread_id']]);
$mattiasAfter = $thread((string) $mattias['thread_id']);
qb(($one['sent'] ?? 0) === 1 && count($GLOBALS['_SENT']) === $sentBefore + 1 && ($mattiasAfter['status'] ?? '') === 'closed' && $rems() === $remBefore + 1, 'dry=0 skickar batterioffert, skapar påminnelse och stänger: ' . json_encode($one['rows'] ?? [], JSON_UNESCAPED_UNICODE));
qb(jeab_inkorg_quote_sent_in_thread($mattiasAfter), 'offerten markeras i ärendet');
$sentBefore = count($GLOBALS['_SENT']);
$twice = jeab_inkorg_hook(['action' => 'inbox.quote_backfill', 'dry' => 0, 'thread' => (string) $mattias['thread_id']]);
qb(($twice['sent'] ?? 1) === 0 && count($GLOBALS['_SENT']) === $sentBefore && (string) ($twice['rows'][0]['action'] ?? '') === 'redan_offert', 'samma lead skickas inte en gång till: ' . json_encode($twice['rows'][0] ?? [], JSON_UNESCAPED_UNICODE));

$prior = jeab_inkorg_ingest([
    'message_id' => 'prior-andy@quote-backfill.test',
    'from' => 'saljare4@jarfalla.example.test',
    'from_name' => 'Elin',
    'to' => 'Andy.Madani@Gmail.com',
    'subject' => 'Hej Andy',
    'body_text' => 'Vi hörde av oss tidigare.',
    'source' => 'outbound-jarfalla',
    'received_at' => date('c', time() - 2 * 86400),
    'preset_labels' => ['type' => 'Information', 'intent' => 'information', 'confidence' => 100, 'offer' => ''],
]);
$andyStatus = (string) ($thread((string) $andy['thread_id'])['status'] ?? '');
$remBefore = $rems();
$sentBefore = count($GLOBALS['_SENT']);
$skipped = jeab_inkorg_hook(['action' => 'inbox.quote_backfill', 'dry' => 0, 'thread' => (string) $andy['thread_id']]);
$andyRow = (array) (($skipped['rows'][0] ?? []));
qb(($andyRow['action'] ?? '') === 'redan_mailad' && ($andyRow['mailed_thread'] ?? '') === (string) $prior['thread_id'] && ($andyRow['masked'] ?? '') === 'a***@g***.com' && ($andyRow['mailed_at'] ?? '') !== '' && ($skipped['sent'] ?? 1) === 0 && count($GLOBALS['_SENT']) === $sentBefore, 'tidigare utskick till samma adress hoppas över: ' . json_encode($andyRow, JSON_UNESCAPED_UNICODE));
qb((string) ($thread((string) $andy['thread_id'])['status'] ?? '') === $andyStatus && $rems() === $remBefore, 'redan mailad ändrar inte ärendet och skapar ingen påminnelse');
qb(!str_contains(json_encode($skipped, JSON_UNESCAPED_UNICODE) ?: '', 'andy.madani@gmail.com'), 'hoppad rad maskar adressen');

$blind = jeab_inkorg_ingest([
    'message_id' => 'blind-costinel@quote-backfill.test',
    'from' => 'saljare3@jarfalla.example.test',
    'from_name' => 'Roberto',
    'to' => 'costinel.badescu@gmail.com',
    'subject' => 'Utan datum',
    'body_text' => 'Okänt när.',
    'source' => 'outbound-jarfalla',
    'preset_labels' => ['type' => 'Information', 'intent' => 'information', 'confidence' => 100, 'offer' => ''],
]);
$clearedDate = false;
$blindId = (string) ($blind['id'] ?? '');
if ($blindId !== '') {
    $wiped = jeab_inkorg_update($blindId, static function (array $msg): array {
        $msg['received_at'] = '';
        $msg['date'] = '';
        $msg['sent_at'] = '';
        $msg['created_at'] = '';

        return $msg;
    });
    $clearedDate = is_array($wiped)
        && (string) ($wiped['received_at'] ?? 'x') === ''
        && (string) ($wiped['date'] ?? 'x') === ''
        && (string) ($wiped['sent_at'] ?? 'x') === ''
        && (string) ($wiped['created_at'] ?? 'x') === '';
}
qb($clearedDate, 'testdubletten kunde skriva bort datumet');
unset(
    $GLOBALS['_JEAB_INKORG_MSGS'],
    $GLOBALS['_JEAB_INKORG_MSGS_FP'],
    $GLOBALS['_JEAB_INKORG_THREADS'],
    $GLOBALS['_JEAB_INKORG_THREADS_FP'],
    $GLOBALS['_JEAB_INKORG_LIGHT']
);
$costinelStatus = (string) ($thread((string) $costinel['thread_id'])['status'] ?? '');
$remBefore = $rems();
$sentBefore = count($GLOBALS['_SENT']);
$unread = jeab_inkorg_hook(['action' => 'inbox.quote_backfill', 'dry' => 0, 'thread' => (string) $costinel['thread_id']]);
$unreadRow = (array) (($unread['rows'][0] ?? []));
qb(($unreadRow['action'] ?? '') === 'history_unread' && str_contains((string) ($unreadRow['why'] ?? ''), 'saknar datum') && ($unread['sent'] ?? 1) === 0 && count($GLOBALS['_SENT']) === $sentBefore, 'oläsbar historik hoppas över: ' . json_encode($unreadRow, JSON_UNESCAPED_UNICODE));
qb((string) ($thread((string) $costinel['thread_id'])['status'] ?? '') === $costinelStatus && $rems() === $remBefore, 'oläsbar historik ändrar inget');

$noPdf = $in('nopdf', 'utan.pdf@gmail.com', 'Utan Pdf', 'Ny lead: Utan Pdf · Villa i Täby', $leadBody('Utan Pdf', 'utan.pdf@gmail.com', 'Täby', '070-100 00 08'), [
    'type' => 'Ny lead', 'intent' => 'kop', 'confidence' => 95, 'offer' => 'laddbox', 'product' => 'laddbox',
]);
@unlink(jeab_auto_quote_pdf_file('laddbox_jarfalla'));
$held = jeab_inkorg_hook(['action' => 'inbox.quote_backfill', 'dry' => 0, 'thread' => (string) $noPdf['thread_id']]);
$heldRow = (array) (($held['rows'][0] ?? []));
qb(($heldRow['action'] ?? '') === 'hold' && ($heldRow['pdf'] ?? 1) === 0 && ($heldRow['template'] ?? 0) === 1 && str_contains((string) ($heldRow['why'] ?? ''), 'PDF') && count($GLOBALS['_SENT']) === $sentBefore, 'saknad PDF håller utan utskick: ' . json_encode($heldRow, JSON_UNESCAPED_UNICODE));

$fresh = $in('fresh', 'ny.kund@gmail.com', 'Ny Kund', 'Ny lead: Ny Kund · Villa i Vallentuna', $leadBody('Ny Kund', 'ny.kund@gmail.com', 'Vallentuna', '070-100 00 09'), $labels('batteri'));
$sentBefore = count($GLOBALS['_SENT']);
$remBefore = $rems();
$auto = jeab_auto_on_message((string) $fresh['id']);
qb(($auto['action'] ?? '') === 'quoted' && count($GLOBALS['_SENT']) === $sentBefore + 1, 'nytt lead skickas när utskicket är på: ' . (string) ($auto['action'] ?? '') . ' ' . (string) ($auto['why'] ?? ''));
$priorMail = jeab_inkorg_ingest([
    'message_id' => 'prior-annan@quote-backfill.test',
    'from' => 'saljare4@jarfalla.example.test',
    'from_name' => 'Elin',
    'to' => 'Annan.Kund@Gmail.com',
    'subject' => 'Hej igen',
    'body_text' => 'Vi hörde av oss om något annat.',
    'source' => 'outbound-jarfalla',
    'received_at' => date('c', time() - 86400),
    'preset_labels' => ['type' => 'Information', 'intent' => 'information', 'confidence' => 100, 'offer' => ''],
]);
$annan = $in('annan', 'annan.kund@gmail.com', 'Annan Kund', 'Ny lead: Annan Kund · Villa i Nacka', $leadBody('Annan Kund', 'annan.kund@gmail.com', 'Nacka', '070-100 00 10'), $labels('batteri'));
$sentBefore = count($GLOBALS['_SENT']);
$remBefore = $rems();
$blocked = jeab_auto_on_message((string) $annan['id']);
qb(($blocked['action'] ?? '') === 'redan_mailad' && ($blocked['sent'] ?? 1) === 0 && ($blocked['mailed_thread'] ?? '') === (string) $priorMail['thread_id'] && count($GLOBALS['_SENT']) === $sentBefore && $rems() === $remBefore && ($thread((string) $annan['thread_id'])['status'] ?? '') === 'open', 'nytt lead till redan mailad adress skickas inte och får ingen påminnelse: ' . json_encode($blocked, JSON_UNESCAPED_UNICODE));
qb(!str_contains(json_encode($blocked, JSON_UNESCAPED_UNICODE) ?: '', 'annan.kund@gmail.com'), 'automatiken lämnar inte adressen i klartext');
$sentBefore = count($GLOBALS['_SENT']);
$remBefore = $rems();
$heldMall = jeab_inkorg_hook(['action' => 'inbox.quote_backfill', 'dry' => 0, 'thread' => (string) $osaker['thread_id']]);
$heldMallRow = (array) (($heldMall['rows'][0] ?? []));
qb(($heldMallRow['action'] ?? '') === 'hold' && str_contains((string) ($heldMallRow['why'] ?? ''), 'osäker solmall') && ($heldMall['sent'] ?? 1) === 0 && count($GLOBALS['_SENT']) === $sentBefore && $rems() === $remBefore + 1, 'oklar solmall skickas inte och hålls med påminnelse: ' . json_encode($heldMallRow, JSON_UNESCAPED_UNICODE));

$wide = jeab_inkorg_hook(['action' => 'inbox.quote_backfill', 'days' => 60, 'thread' => (string) $old['thread_id']]);
qb((($wide['rows'][0]['id'] ?? '') === (string) $old['thread_id']) && ($wide['days'] ?? 0) === 60 && ($wide['sent'] ?? 1) === 0, 'days tar med äldre lead i dry-run');

jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode('%PDF-1.4 ladd')]);
$threadsBefore = count(jeab_inkorg_threads());
$remBefore = $rems();
$sentBefore = count($GLOBALS['_SENT']);
$preview = jeab_inkorg_hook(['action' => 'inbox.quote_test']);
qb(($preview['ok'] ?? false) === true && ($preview['dry'] ?? 0) === 1 && ($preview['sent'] ?? 1) === 0 && count($GLOBALS['_SENT']) === $sentBefore && $rems() === $remBefore && count(jeab_inkorg_threads()) === $threadsBefore, 'quote_test dry skickar inget och rör inget ärende');
$previewBy = [];
foreach ((array) ($preview['rows'] ?? []) as $row) {
    $previewBy[(string) ($row['id'] ?? '')] = $row;
}
foreach (['sol_kopt' => 'A', 'sol_eget' => 'B'] as $id => $letter) {
    $row = $previewBy[$id] ?? [];
    qb(($row['name'] ?? '') !== '' && ($row['mall'] ?? '') === $letter && ($row['template'] ?? 0) === 1 && ($row['pdf'] ?? 0) === 1 && ($row['action'] ?? '') === 'would_send' && str_starts_with((string) ($row['subject'] ?? ''), '[TEST] ') && in_array('Solceller offert 2608 Demo Energi Järfälla.pdf', (array) ($row['files'] ?? []), true), 'dry visar solmall ' . $letter . ': ' . json_encode($row, JSON_UNESCAPED_UNICODE));
}
$batt = $previewBy['batteri_jarfalla'] ?? [];
qb(($batt['name'] ?? '') === 'Batteri' && ($batt['action'] ?? '') === 'would_send' && in_array('Batteri-offert.pdf', (array) ($batt['files'] ?? []), true) && ($batt['missing'] ?? 'x') === '', 'dry visar batterimallen med PDF');
$ladd = $previewBy['laddbox_jarfalla'] ?? [];
qb(($ladd['action'] ?? '') === 'would_send' && in_array('Elbilsladdare-offert.pdf', (array) ($ladd['files'] ?? []), true), 'dry visar laddboxmallen med PDF');
qb(($preview['would_send'] ?? false) === true, 'dry säger att något skulle skickas');

@unlink(jeab_auto_quote_pdf_file('laddbox_jarfalla'));
$missingPreview = jeab_inkorg_hook(['action' => 'inbox.quote_test']);
$missingLadd = [];
foreach ((array) ($missingPreview['rows'] ?? []) as $row) {
    if ((string) ($row['id'] ?? '') === 'laddbox_jarfalla') {
        $missingLadd = $row;
    }
}
qb(($missingLadd['action'] ?? '') === 'missing' && ($missingLadd['pdf'] ?? 1) === 0 && ($missingLadd['would_send'] ?? true) === false && str_contains((string) ($missingLadd['missing'] ?? ''), 'PDF') && count($GLOBALS['_SENT']) === $sentBefore, 'saknad laddbox-PDF listas och skickas inte: ' . json_encode($missingLadd, JSON_UNESCAPED_UNICODE));
jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode('%PDF-1.4 ladd')]);

$threadsBefore = count(jeab_inkorg_threads());
$remBefore = $rems();
$sentBefore = count($GLOBALS['_SENT']);
$refused = jeab_inkorg_hook(['action' => 'inbox.quote_test', 'dry' => 0, 'to' => 'kund@gmail.com']);
$refusedJson = json_encode($refused, JSON_UNESCAPED_UNICODE) ?: '';
qb(($refused['ok'] ?? true) === false && ($refused['sent'] ?? 1) === 0 && count($GLOBALS['_SENT']) === $sentBefore && $rems() === $remBefore && count(jeab_inkorg_threads()) === $threadsBefore && !str_contains($refusedJson, 'kund@gmail.com'), 'kundadress nekas och syns inte i svaret');
$staffRefuse = jeab_inkorg_hook(['action' => 'inbox.quote_test', 'dry' => 0, 'to' => 'saljare4@jarfalla.example.test']);
qb(($staffRefuse['ok'] ?? true) === false && ($staffRefuse['sent'] ?? 1) === 0 && count($GLOBALS['_SENT']) === $sentBefore, 'annan personaladress nekas');

$sentBefore = count($GLOBALS['_SENT']);
$remBefore = $rems();
$threadsBefore = count(jeab_inkorg_threads());
$copy = jeab_inkorg_hook(['action' => 'inbox.quote_test', 'dry' => 0, 'to' => 'Sam@jarfalla-demo.se']);
qb(($copy['ok'] ?? false) === true && ($copy['sent'] ?? 0) >= 4 && count($GLOBALS['_SENT']) === $sentBefore + (int) $copy['sent'] && $rems() === $remBefore && count(jeab_inkorg_threads()) === $threadsBefore, 'testkopia skickas bara till Sam och rör inget ärende: ' . json_encode(['sent' => $copy['sent'] ?? 0, 'rows' => array_map(static fn ($row) => ['id' => $row['id'] ?? '', 'action' => $row['action'] ?? '', 'missing' => $row['missing'] ?? ''], (array) ($copy['rows'] ?? []))], JSON_UNESCAPED_UNICODE));
$copyBy = [];
foreach ((array) ($copy['rows'] ?? []) as $row) {
    $copyBy[(string) ($row['id'] ?? '')] = $row;
}
foreach (['sol_kopt', 'sol_eget', 'batteri_jarfalla', 'laddbox_jarfalla'] as $id) {
    qb(($copyBy[$id]['action'] ?? '') === 'sent' && ($copyBy[$id]['sent'] ?? 0) === 1, 'testkopia skickade ' . $id);
}
$tos = [];
$subjects = [];
foreach (array_slice($GLOBALS['_SENT'], $sentBefore) as $mail) {
    $tos[] = mb_strtolower((string) ($mail['to'] ?? ''));
    $subjects[] = (string) ($mail['subject'] ?? '');
    qb(str_starts_with((string) ($mail['subject'] ?? ''), '[TEST] ') && (array) ($mail['files'] ?? []) !== [], 'varje testmejl har prefix och bilaga');
}
qb($tos !== [] && count(array_unique($tos)) === 1 && $tos[0] === 'demo@example.test', 'alla testmejl går till demo@example.test');
qb(!str_contains(json_encode($copy, JSON_UNESCAPED_UNICODE) ?: '', 'kund@') && !str_contains(implode(' ', $subjects), 'kund@'), 'testsvar och ämnen saknar kundadress');

echo "inkorg quote backfill: ok\n";
