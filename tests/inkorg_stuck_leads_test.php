<?php

declare(strict_types=1);

/**
 * inbox.stuck_leads. Torr lista är bara tråd-id.
 * dry=0 kör den vanliga kedjan en gång. En andra körning skickar inte.
 */

$tmp = sys_get_temp_dir() . '/jeab-stuck-leads-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function sl(bool $ok, string $m): void
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

    return ['ok' => true, 'from' => 'saljare4@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'grok_decides' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'unknown_product_ask_enabled' => 0,
    'follow_receipt_enabled' => 0,
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
$pdfDir = dirname(jeab_auto_pdf_path('laddbox_jarfalla'));
if (!is_dir($pdfDir)) {
    mkdir($pdfDir, 0775, true);
}
file_put_contents(jeab_auto_pdf_path('laddbox_jarfalla'), "%PDF-1.1\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

$box = 'kontakt@jarfalla.example.test';
$since = '2026-10-20T00:00:00+02:00';
$lead = static function (string $offer = '', bool $review = false): array {
    return [
        'type' => 'Ny lead',
        'action' => 'svara',
        'priority' => 1,
        'confidence' => $review ? 70 : 92,
        'intent' => 'kop',
        'offer' => $offer,
        'product' => $offer === 'laddbox' ? 'elbilsladdare' : $offer,
        'lead' => 'eget',
        'question' => $offer === '' ? 'Ferroamp fasbalansering' : '',
        'reason' => $review ? 'Behöver granskas' : 'klassad',
        'source' => 'llm',
        'needs_review' => $review,
    ];
};
$ingest = static function (string $id, string $from, string $subject, string $body, array $labels, string $at) use ($box): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@stuck-leads.test',
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
    ]);
};
$form = static function (string $name, string $email, string $phone, string $text): string {
    return "A site visitor just submitted your form: Kontakt Formulär\n\nNamn:\n\n{$name}\n\nE-post:\n\n{$email}\n\nTelefon:\n\n{$phone}\n\nAdress:\n\nStorgatan 1\n\nPostnummer:\n\n177 31\n\nOrt:\n\nJärfälla\n\nMeddelande:\n\n{$text}\n";
};

$at = '2026-10-23T14:55:00+02:00';
$ferro = $ingest(
    'stuck-ferro',
    'no-reply@crm.wix.com',
    'JEAB Kontakt Formulär',
    $form('Maja Ferro', 'maja.stuck@gmail.com', '070-555 19 19', 'Ferroamp fasbalansering'),
    $lead(),
    $at
);
$send = $ingest(
    'stuck-send',
    'noa.ladd@gmail.com',
    'Elbilsladdare',
    "Namn: Noa Ladd\nE-post: noa.ladd@gmail.com\nTelefon: 070-555 21 21\nAdress: Storgatan 2, 177 31 Järfälla\nElbilsladdare\n",
    $lead('laddbox'),
    '2026-10-23T15:10:00+02:00'
);
$review = $ingest(
    'stuck-review',
    'no-reply@crm.wix.com',
    'JEAB Kontakt Formulär',
    $form('Disa Review', 'disa.review@gmail.com', '070-555 22 22', 'Hej'),
    $lead('', true),
    $at
);
$old = $ingest(
    'stuck-old',
    'no-reply@crm.wix.com',
    'JEAB Kontakt Formulär',
    $form('Ove Old', 'ove.old@gmail.com', '070-555 23 23', 'Ferroamp fasbalansering'),
    $lead(),
    '2026-10-01T08:00:00+02:00'
);
$info = $ingest(
    'stuck-info',
    'disa.info@gmail.com',
    'Information',
    "Hej, vi vill köpa och installera ett batteri.\n",
    ['type' => 'Information', 'confidence' => 92, 'intent' => 'information', 'offer' => '', 'product' => '', 'needs_review' => false, 'source' => 'llm', 'question' => ''],
    $at
);
$quoted = $ingest(
    'stuck-quoted',
    'noa.quoted@gmail.com',
    'Redan offert',
    "Namn: Noa Quoted\nE-post: noa.quoted@gmail.com\nTelefon: 070-555 24 24\nAdress: Storgatan 3, 177 31 Järfälla\nElbilsladdare\n",
    $lead('laddbox'),
    $at
);
$quotedTid = (string) ($quoted['thread_id'] ?? '');
jeab_inkorg_add_draft($quotedTid, ['kind' => 'note', 'body' => 'Offert (Elbilsladdare) skickad'], 'test');
$reminded = $ingest(
    'stuck-reminded',
    'no-reply@crm.wix.com',
    'JEAB Kontakt Formulär',
    $form('Mia Remind', 'mia.remind@gmail.com', '070-555 25 25', 'Ferroamp fasbalansering'),
    $lead(),
    $at
);
$ferroTid = (string) ($ferro['thread_id'] ?? '');
$sendTid = (string) ($send['thread_id'] ?? '');
$reviewTid = (string) ($review['thread_id'] ?? '');
$oldTid = (string) ($old['thread_id'] ?? '');
$infoTid = (string) ($info['thread_id'] ?? '');
$remindedTid = (string) ($reminded['thread_id'] ?? '');

$missing = jeab_inkorg_hook(['action' => 'inbox.stuck_leads']);
sl(($missing['ok'] ?? true) === false && ($missing['error'] ?? '') === 'since krävs' && ($missing['sent'] ?? 1) === 0, 'since krävs');

$dry = jeab_inkorg_hook(['action' => 'inbox.stuck_leads', 'since' => $since]);
sl(($dry['ok'] ?? false) === true && ($dry['dry'] ?? 0) === 1 && ($dry['sent'] ?? 1) === 0, 'dry är standard och skickar inget');
$want = [$ferroTid, $sendTid, $remindedTid];
sort($want, SORT_STRING);
sl(($dry['threads'] ?? null) === $want, 'listan är de fastnade trådarna, fick ' . json_encode($dry['threads'] ?? null));
sl(!str_contains((string) json_encode($dry, JSON_UNESCAPED_UNICODE), '@'), 'torrlistan har ingen kunddata');
sl(count($GLOBALS['_SENT']) === 0, 'torrkörningen skickar inget');
$again = jeab_inkorg_hook(['action' => 'inbox.stuck_leads', 'since' => $since, 'dry' => 1]);
sl(($again['threads'] ?? null) === ($dry['threads'] ?? null) && count(jeab_list_reminders(jeab_store(), true)) === 0, 'en andra torrkörning skriver inget');

$one = jeab_inkorg_hook(['action' => 'inbox.stuck_leads', 'since' => $since, 'thread' => $ferroTid]);
sl(($one['threads'] ?? null) === [$ferroTid], 'thread begränsar torrlistan till ett id');

$sentBefore = count($GLOBALS['_SENT']);
$wet = jeab_inkorg_hook(['action' => 'inbox.stuck_leads', 'since' => $since, 'dry' => 0, 'thread' => $ferroTid]);
$wetRow = (array) (($wet['threads'] ?? [])[0] ?? []);
$ferroRems = 0;
$ferroWho = '';
foreach (jeab_list_reminders(jeab_store(), true) as $rem) {
    if (!is_array($rem) || (string) ($rem['thread_id'] ?? '') !== $ferroTid) {
        continue;
    }
    $ferroRems++;
    $ferroWho = (string) ($rem['assignee'] ?? '');
}
$ferroLabels = (array) (jeab_inkorg_get((string) ($ferro['id'] ?? ''))['labels'] ?? []);
sl(($wet['dry'] ?? 1) === 0 && ($wet['sent'] ?? 1) === 0 && count($GLOBALS['_SENT']) === $sentBefore, 'Ferroamp-kedjan skickar ingen offert');
sl($ferroRems === 1 && strcasecmp($ferroWho, 'Sam L') === 0, 'Ferroamp får en påminnelse hos Sam L, fick ' . $ferroRems . ' / ' . $ferroWho);
sl((string) (jeab_inkorg_thread_meta($ferroTid)['assignee'] ?? '') === 'SamL', 'Ferroamp-ärendet tilldelas SamL');
sl(empty($ferroLabels['needs_review']) && ($wetRow['action'] ?? '') !== 'none', 'påminnelsen ersätter tyst none');
sl(!str_contains((string) json_encode($wet, JSON_UNESCAPED_UNICODE), '@'), 'dry=0-svaret har ingen kunddata');

$afterFerro = jeab_inkorg_hook(['action' => 'inbox.stuck_leads', 'since' => $since]);
sl(($afterFerro['threads'] ?? null) === [$sendTid], 'körd tråd försvinner ur listan');
$wetAgain = jeab_inkorg_hook(['action' => 'inbox.stuck_leads', 'since' => $since, 'dry' => 0, 'thread' => $ferroTid]);
sl(($wetAgain['threads'] ?? null) === [] && ($wetAgain['sent'] ?? 1) === 0 && count($GLOBALS['_SENT']) === $sentBefore, 'samma tråd en gång till skickar inte och skriver ingen ny påminnelse');
$ferroRemsAfter = 0;
foreach (jeab_list_reminders(jeab_store(), true) as $rem) {
    if (is_array($rem) && (string) ($rem['thread_id'] ?? '') === $ferroTid) {
        $ferroRemsAfter++;
    }
}
sl($ferroRemsAfter === 1, 'påminnelsen skapas bara en gång');

$sentBeforeSend = count($GLOBALS['_SENT']);
$wetSend = jeab_inkorg_hook(['action' => 'inbox.stuck_leads', 'since' => $since, 'dry' => 0, 'thread' => $sendTid]);
$sendRow = (array) (($wetSend['threads'] ?? [])[0] ?? []);
sl(($sendRow['action'] ?? '') === 'quoted' && ($wetSend['sent'] ?? 0) === 1 && count($GLOBALS['_SENT']) === $sentBeforeSend + 1, 'första kontakten med e-post får en offert, fick ' . (string) ($sendRow['action'] ?? ''));
$sendRems = 0;
foreach (jeab_list_reminders(jeab_store(), true) as $rem) {
    if (is_array($rem) && (string) ($rem['thread_id'] ?? '') === $sendTid) {
        $sendRems++;
    }
}
sl($sendRems === 1, 'offerten får en påminnelse');
$wetSendAgain = jeab_inkorg_hook(['action' => 'inbox.stuck_leads', 'since' => $since, 'dry' => 0, 'thread' => $sendTid]);
sl(($wetSendAgain['threads'] ?? ['x']) === [] && ($wetSendAgain['sent'] ?? 1) === 0 && count($GLOBALS['_SENT']) === $sentBeforeSend + 1, 'samma offert skickas inte en gång till');
$left = jeab_inkorg_hook(['action' => 'inbox.stuck_leads', 'since' => $since]);
$leftIds = (array) ($left['threads'] ?? []);
sl($leftIds === [] && !in_array($reviewTid, $leftIds, true) && !in_array($quotedTid, $leftIds, true) && !in_array($oldTid, $leftIds, true) && !in_array($infoTid, $leftIds, true) && !in_array($remindedTid, $leftIds, true), 'granskning, offert, gammalt, information och påmind tråd listas inte');

$testFlag = $ingest(
    'stuck-testflag',
    'no-reply@crm.wix.com',
    'JEAB Kontakt Formulär',
    $form('Vera Testflagga', 'vera.testflagga@gmail.com', '070-555 31 31', 'Ferroamp fasbalansering'),
    $lead() + ['is_test' => true],
    $at
);
$orderLead = $ingest(
    'stuck-orderlink',
    'no-reply@crm.wix.com',
    'JEAB Kontakt Formulär',
    $form('Otto Order', 'otto.orderlink@gmail.com', '070-555 32 32', 'Ferroamp fasbalansering'),
    $lead(),
    $at
);
$contactLead = $ingest(
    'stuck-contactlink',
    'no-reply@crm.wix.com',
    'JEAB Kontakt Formulär',
    $form('Cilla Kontakt', 'cilla.contactlink@gmail.com', '070-555 33 33', 'Ferroamp fasbalansering'),
    $lead(),
    $at
);
$testTid = (string) ($testFlag['thread_id'] ?? '');
$orderTid = (string) ($orderLead['thread_id'] ?? '');
$contactTid = (string) ($contactLead['thread_id'] ?? '');
$orderRem = jeab_create_reminder(jeab_store(), 'Orderlänk 1001', null, 'redan kopplad order', null, [
    'email' => 'annan.order@gmail.com',
    'customer_name' => 'Annan Order',
    'contact_id' => 'cnt-order-link',
    'lookup' => false,
]);
$contactRem = jeab_create_reminder(jeab_store(), 'Kontaktlänk', null, 'redan kopplad kontakt', null, [
    'email' => 'annan.kontakt@gmail.com',
    'customer_name' => 'Annan Kontakt',
    'contact_id' => 'cnt-contact-link',
    'lookup' => false,
]);
$orderRid = (int) ($orderRem['id'] ?? 0);
$contactRid = (int) ($contactRem['id'] ?? 0);
sl($orderRid > 0 && $contactRid > 0 && $orderRid !== $contactRid, 'order- och kontaktlänk har vars en påminnelse');
jeab_inkorg_update((string) ($orderLead['id'] ?? ''), static function (array $m) use ($orderRid): array {
    $labels = (array) ($m['labels'] ?? []);
    $case = (array) ($labels['case_class'] ?? []);
    $case['order_link'] = ['reminder_id' => $orderRid, 'number' => '1001', 'via' => 'order'];
    $labels['case_class'] = $case;
    $m['labels'] = $labels;

    return $m;
});
jeab_inkorg_thread_write($contactTid, static function (array $t): array {
    $t['contact_id'] = 'cnt-contact-link';

    return $t;
});
$remCount = count(jeab_list_reminders(jeab_store(), true));
$sentBeforeSkip = count($GLOBALS['_SENT']);
$drySkip = jeab_inkorg_hook(['action' => 'inbox.stuck_leads', 'since' => $since]);
$skipIds = array_merge((array) ($drySkip['threads'] ?? []), array_column((array) ($drySkip['links'] ?? []), 'id'));
sl(!in_array($testTid, $skipIds, true), 'is_test listas inte');
sl(!in_array($orderTid, $skipIds, true), 'orderlänk räknas som hanterad');
sl(!in_array($contactTid, $skipIds, true), 'kontaktlänk räknas som hanterad');
foreach ([$testTid, $orderTid, $contactTid] as $skipTid) {
    $wetSkip = jeab_inkorg_hook(['action' => 'inbox.stuck_leads', 'since' => $since, 'dry' => 0, 'thread' => $skipTid]);
    sl(($wetSkip['threads'] ?? ['x']) === [] && ($wetSkip['links'] ?? ['x']) === [] && ($wetSkip['sent'] ?? 1) === 0, 'dry=0 rör inte ' . $skipTid);
}
sl(count(jeab_list_reminders(jeab_store(), true)) === $remCount && count($GLOBALS['_SENT']) === $sentBeforeSkip, 'varken test, orderlänk eller kontaktlänk skapar en ny påminnelse eller offert');
$orderNote = (string) (jeab_get_reminder(jeab_store(), $orderRid)['note'] ?? '');
$contactNote = (string) (jeab_get_reminder(jeab_store(), $contactRid)['note'] ?? '');
sl(!str_contains($orderNote, $orderTid) && !str_contains($contactNote, $contactTid), 'länkad påminnelse får inte ärendet påskrivet');

echo "stuck leads: ok\n";
