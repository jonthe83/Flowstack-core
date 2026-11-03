<?php

declare(strict_types=1);

/** Kvittens signeras av avsändaren. Testadress får ingen kvittens. Wix-kalkyl är ett sol-lead. */

$tmp = sys_get_temp_dir() . '/jeab-follow-sender-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function fsnd(bool $ok, string $m): void
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
    $person = trim((string) ($a['opts']['person_email'] ?? ''));
    $from = $person !== '' ? $person : jeab_graph_mailboxes()[$a['company']];
    return ['ok' => true, 'from' => $from, 'graph_id' => 'g-sender', 'conversation_id' => 'CVS', 'message_id' => 'sent-sender-' . count($GLOBALS['_SENT']) . '@x'];
};

jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode("%PDF-1.4 sender")]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode("%PDF-1.4 sender-b")]);
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);

$in = function (string $id, string $from, string $name, array $labels, string $body, string $subject = 'Laddbox hemma', string $thread = '') {
    $row = [
        'message_id' => $id . '@follow-sender.test',
        'from' => $from,
        'from_name' => $name,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => $subject,
        'body_text' => $body,
        'received_at' => '2026-10-19T08:15:00+02:00',
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'graph_id' => 'graph-' . $id,
        'preset_labels' => $labels,
    ];
    if ($thread !== '') {
        $row['thread_key'] = $thread;
    }

    return jeab_inkorg_ingest($row);
};

$ask = [
    'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
    'product' => 'elbilsladdare', 'intent' => 'kop', 'offer' => 'laddbox', 'question' => 'Kan ni ringa?', 'reason' => 'vill bli uppringd',
];

$regler = (string) file_get_contents(dirname(__DIR__) . '/src/pages/inkorg_regler.php');
fsnd(substr_count($regler, 'name="follow_receipt_enabled"') === 1, 'kvittensbrytaren finns på ett ställe på Regler');
$settingsPath = jeab_inkorg_dir() . '/settings.json';
$stored = json_decode((string) file_get_contents($settingsPath), true);
fsnd(is_array($stored), 'inställningarna går att läsa');
unset($stored['follow_receipt_enabled']);
file_put_contents($settingsPath, json_encode($stored, JSON_UNESCAPED_UNICODE));
fsnd(jeab_auto_settings()['follow_receipt_enabled'] === false && jeab_follow_receipt_enabled() === false, 'saknad kvittensbrytare är av');

$offRem = jeab_create_reminder(jeab_store(), 'Ida Off – Elbilsladdare', jeab_today_start(), null, null, [
    'customer' => 'Ida Off',
    'email' => 'ida.off.sender@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Sam L',
    'note' => 'kvittens av',
]);
jeab_toggle_reminder(jeab_store(), (int) $offRem['id'], true);
// «kvittens av» är inte en skickad offert. Första mejlet ska få offerten.
// Kvittens av provas på svaret i samma ärende, efter den offerten.
$idaFirst = $in('ida-off', 'ida.off.sender@gmail.com', 'Ida Off', $ask, 'Kan ni ringa ikväll?');
$idaQuoted = jeab_auto_on_message((string) $idaFirst['id']);
fsnd(($idaQuoted['action'] ?? '') === 'quoted', 'påminnelse utan offert får första offerten');
$idaQuoteId = (int) ($idaQuoted['reminder'] ?? $offRem['id']);
$idaQuotedRow = jeab_get_reminder(jeab_store(), $idaQuoteId);
if (is_array($idaQuotedRow) && empty($idaQuotedRow['completed_at'])) {
    jeab_toggle_reminder(jeab_store(), $idaQuoteId, true);
}
$before = count($GLOBALS['_SENT']);
$ida = $in('ida-off-2', 'ida.off.sender@gmail.com', 'Ida Off', $ask, 'Kan ni ringa ikväll?', 'Laddbox hemma', (string) $idaFirst['thread_id']);
$idaRes = jeab_auto_on_message((string) $ida['id']);
$idaNow = jeab_get_reminder(jeab_store(), (int) $offRem['id']);
$idaThread = jeab_auto_thread_by_id((string) $ida['thread_id']);
$idaNotes = '';
foreach ((array) ($idaThread['drafts'] ?? []) as $draft) {
    if (is_array($draft)) {
        $idaNotes .= "\n" . (string) ($draft['body'] ?? '');
    }
}
fsnd(
    ($idaRes['action'] ?? '') === 'follow'
    && (int) ($idaRes['receipt'] ?? 1) === 0
    && ($idaRes['from'] ?? '') === ''
    && count($GLOBALS['_SENT']) === $before
    && is_array($idaNow)
    && empty($idaNow['completed_at'])
    && str_contains((string) $idaNow['note'], ', ring.')
    && str_contains($idaNotes, 'kvittensutskick är av'),
    'av som standard: ingen kvittens, påminnelsen öppnas med ring'
);

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'follow_receipt_enabled' => 1,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
fsnd(jeab_follow_receipt_enabled() === true, 'påslagen kvittensbrytare är på');

$jon = jeab_create_reminder(jeab_store(), 'Sara Holm – Elbilsladdare', jeab_today_start(), null, null, [
    'customer' => 'Sara Holm',
    'email' => 'sara.holm.sender@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Sam L',
    'note' => 'ägaren är Sam, produkten är laddbox',
]);
jeab_toggle_reminder(jeab_store(), (int) $jon['id'], true);
$saraFirst = $in('sara', 'sara.holm.sender@gmail.com', 'Sara Holm', $ask, 'Kan ni ringa ikväll?');
$saraQuoted = jeab_auto_on_message((string) $saraFirst['id']);
fsnd(($saraQuoted['action'] ?? '') === 'quoted', 'Sams påminnelse utan offert får första offerten');
$saraQuoteId = (int) ($saraQuoted['reminder'] ?? $jon['id']);
$saraQuotedRow = jeab_get_reminder(jeab_store(), $saraQuoteId);
if (is_array($saraQuotedRow) && empty($saraQuotedRow['completed_at'])) {
    jeab_toggle_reminder(jeab_store(), $saraQuoteId, true);
}
$before = count($GLOBALS['_SENT']);
$sara = $in('sara-2', 'sara.holm.sender@gmail.com', 'Sara Holm', $ask, 'Kan ni ringa ikväll?', 'Laddbox hemma', (string) $saraFirst['thread_id']);
$saraRes = jeab_auto_on_message((string) $sara['id']);
$saraSend = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
$saraBody = (string) ($saraSend['body'] ?? '');
fsnd(($saraRes['action'] ?? '') === 'follow' && ($saraRes['receipt'] ?? 0) === 1 && ($saraRes['from'] ?? '') === 'demo@example.test', 'kvittensen går från Sam');
fsnd(count($GLOBALS['_SENT']) === $before + 1 && str_contains($saraBody, 'Sam Exempel') && str_contains($saraBody, '08-000 00 02') && !str_contains($saraBody, 'Elin') && !str_contains($saraBody, '070-000 00 05'), 'ägaren Sam ger Sams signatur, inte Elins');

$unknown = [
    'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
    'intent' => 'kop', 'offer' => '', 'product' => '', 'question' => 'Kan ni ringa?', 'reason' => 'okänd produkt',
];
$niman = jeab_create_reminder(jeab_store(), 'Noel Berg', jeab_today_start(), null, null, [
    'customer' => 'Noel Berg',
    'email' => 'noel.berg.sender@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Niman',
    'note' => 'okänd produkt',
]);
jeab_toggle_reminder(jeab_store(), (int) $niman['id'], true);
$before = count($GLOBALS['_SENT']);
$noel = $in('noel', 'noel.berg.sender@gmail.com', 'Noel Berg', $unknown, 'Kan ni ringa?');
$noelRes = jeab_auto_on_message((string) $noel['id']);
$noelThread = jeab_auto_thread_by_id((string) $noel['thread_id']);
$noelNotes = '';
foreach ((array) ($noelThread['drafts'] ?? []) as $draft) {
    if (is_array($draft)) {
        $noelNotes .= "\n" . (string) ($draft['body'] ?? '');
    }
}
$sentAfter = '';
foreach (array_slice($GLOBALS['_SENT'], $before) as $row) {
    $sentAfter .= "\n" . (string) ($row['body'] ?? '');
}
fsnd(
    ($noelRes['action'] ?? '') === 'hold'
    && ($noelRes['why'] ?? '') === 'ingen känd offertprodukt'
    && (int) ($noelRes['receipt'] ?? 0) === 0
    && count($GLOBALS['_SENT']) === $before
    && !str_contains($sentAfter, 'Elin')
    && str_contains($noelNotes, 'ingen känd offertprodukt'),
    'okänd produkt skickar ingen offert och ingen kvittens'
);

$testRem = jeab_create_reminder(jeab_store(), 'Testkund Exempel – Elbilsladdare', jeab_today_start(), null, null, [
    'customer' => 'Testkund Exempel',
    'email' => 'testkund@example.com',
    'company' => 'jarfalla',
    'who' => 'Elin',
    'note' => 'testadress',
]);
jeab_toggle_reminder(jeab_store(), (int) $testRem['id'], true);
$before = count($GLOBALS['_SENT']);
$testIn = $in('testkund', 'testkund@example.com', 'Testkund Exempel', $ask, 'Kan ni ringa?');
$testRes = jeab_auto_on_message((string) $testIn['id']);
$testNow = jeab_get_reminder(jeab_store(), (int) $testRem['id']);
fsnd(
    count($GLOBALS['_SENT']) === $before
    && is_array($testNow)
    && !empty($testNow['completed_at'])
    && (int) ($testRes['receipt'] ?? 0) === 0
    && ($testRes['action'] ?? '') !== 'follow'
    && !str_contains((string) ($testNow['note'] ?? ''), 'Kunden svarade'),
    'testadress ger ingen kvittens och öppnar inte påminnelsen'
);

$kalkylBody = "A site visitor just submitted your form JEAB Solceller kalkyl\n\nSubmission summary:\n\nNamn:\n\nMaja Kalkyl\n\nAdress:\n\nKalkylvägen 2, 176 70 Järfälla\n\nTelefon:\n\n070-222 33 44\n\nE-post:\n\nmaja.kalkyl.sender@gmail.com\n";
$kalkylMsg = [
    'from' => 'info@wix-forms.com',
    'from_name' => 'Formulär JEAB',
    'subject' => 'JEAB Solceller kalkyl lead',
    'body' => $kalkylBody,
];
$kalkylCust = jeab_lead_customer($kalkylMsg);
fsnd(
    jeab_lead_wix_kalkyl_sol($kalkylMsg)
    && jeab_lead_infer_offer($kalkylMsg) === ''
    && $kalkylCust['name'] === 'Maja Kalkyl'
    && $kalkylCust['email'] === 'maja.kalkyl.sender@gmail.com'
    && $kalkylCust['phone'] === '070-222 33 44'
    && str_contains((string) $kalkylCust['address'], 'Kalkylvägen 2'),
    'Wix-kalkyl lead tolkas som sol med namn, e-post, telefon och adress'
);

$old = $in('old-wix', 'info@wix-forms.com', 'Formulär JEAB', [
    'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 90, 'intent' => 'kop', 'offer' => 'batteri',
], "A site visitor just submitted your form Batteri lead\n\nNamn:\n\nAnnan Kund\n\nE-post:\n\nannan.kund.sender@gmail.com\n", 'JEAB Batteri lead');
jeab_auto_note((string) $old['thread_id'], 'Offert (Batteri) skickad');
$lead = jeab_inkorg_ingest([
    'message_id' => 'kalkyl@follow-sender.test',
    'from' => 'info@wix-forms.com',
    'from_name' => 'Formulär JEAB',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'JEAB Solceller kalkyl lead',
    'body_text' => $kalkylBody,
    'received_at' => '2026-10-19T09:00:00+02:00',
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'graph_id' => 'graph-kalkyl',
]);
$leadRes = jeab_auto_on_message((string) $lead['id']);
$leadThread = jeab_auto_thread_by_id((string) ($lead['thread_id'] ?? ''));
fsnd(
    ($lead['thread_id'] ?? '') !== ''
    && ($lead['thread_id'] ?? '') !== ($old['thread_id'] ?? '')
    && is_array($leadThread)
    && ($leadRes['action'] ?? '') !== 'follow',
    'Wix-kalkyl lead blir ett eget inkorgsärende och inte bara en kvittens'
);

$before = count($GLOBALS['_SENT']);
$own = $in('own', 'demo.person@example.invalid', 'Sam', $ask, 'Hej, jag vill ha en laddbox.');
$ownRes = jeab_auto_on_message((string) $own['id']);
$ownThread = jeab_auto_thread_by_id((string) $own['thread_id']);
$ownNotes = '';
foreach ((array) ($ownThread['drafts'] ?? []) as $draft) {
    if (is_array($draft)) {
        $ownNotes .= "\n" . (string) ($draft['body'] ?? '');
    }
}
fsnd(
    ($own['thread_id'] ?? '') !== ''
    && ($ownRes['action'] ?? '') === 'historik'
    && ($ownRes['why'] ?? '') === 'egen'
    && count($GLOBALS['_SENT']) === $before,
    'nytt lead från Sams adress är egen post och skickar inget'
);

echo "done\n";
