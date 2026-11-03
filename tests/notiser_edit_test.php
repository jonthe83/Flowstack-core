<?php

declare(strict_types=1);

/**
 * Notiser: sparad text och PDF används av offert, bekräftelse och SMS.
 * Påhittad kund. Mail och SMS är stubbade.
 */

$tmp = sys_get_temp_dir() . '/jeab-notiser-edit-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ne(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$who = 'redaktor@exempel.se';
$name = 'Fejk Kund';
$email = 'fejk.kund@exempel.se';
$phone = '+46700000001';
$leadFor = static function (string $addr, string $leadName, string $leadPhone): string {
    return "Namn: {$leadName}\nE-post: {$addr}\nTelefon: {$leadPhone}\nAdress: Fejkgatan 1, 176 70 Järfälla\n";
};

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = function (array $a) {
    $GLOBALS['_SENT'][] = $a;
    return ['ok' => true, 'from' => 'saljare4@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
$GLOBALS['_MAIL'] = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = function (array $opts) {
    $GLOBALS['_MAIL'][] = $opts;
    return ['ok' => true, 'path' => 'stub', 'id' => 'm1'];
};
$GLOBALS['_SMS'] = [];
$GLOBALS['JEAB_SMS_SEND'] = function (string $to, string $text) {
    $GLOBALS['_SMS'][] = ['to' => $to, 'text' => $text];
    return ['ok' => true];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = fn() => ['code' => 200, 'body' => ['value' => []]];
$GLOBALS['_JEAB_REBOOK_LLM'] = fn() => json_encode(['answer' => 'Ja, det ingår.']);
@mkdir(jeab_data_dir() . '/secrets', 0775, true);
file_put_contents(jeab_data_dir() . '/secrets/inkorg-llm.json', json_encode(['key' => 'test']));

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_inkorg_helpscout_companies_save(['helpscout_companies' => []]);
$oldPdf = "%PDF-1.4 GAMMAL\n";
$newPdf = "%PDF-1.4 NYPDF\n";
jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode($oldPdf)]);
jeab_auto_pdf_save(['key' => 'sol_jarfalla', 'data' => base64_encode("%PDF-1.4 sol\n")]);

$ingest = static function (string $id, string $from, string $offer, string $addr, string $leadName, string $leadPhone) use ($leadFor): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@ne.test',
        'from' => $from,
        'from_name' => 'Formulär',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Offert',
        'body_text' => $leadFor($addr, $leadName, $leadPhone),
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'graph_id' => 'graph-' . $id,
        'thread_key' => 'ne-' . $id,
        'preset_labels' => [
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
            'intent' => 'kop', 'offer' => $offer, 'product' => $offer, 'question' => '',
        ],
        'headers' => ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass'],
    ]);
};
$pdfOf = static function (array $atts): string {
    foreach ($atts as $att) {
        if (str_ends_with(strtolower((string) ($att['filename'] ?? '')), '.pdf')) {
            return (string) ($att['content'] ?? '');
        }
    }
    return '';
};

jeab_notiser_save_body('offert-laddbox-jarfalla', "Hej\n\nNY-OFFERT-TEXT-FEJK", $who);
jeab_notiser_save_pdf('offert-laddbox-jarfalla', $newPdf, $who);
$byId = [];
foreach (jeab_notiser_list()['items'] as $item) {
    $byId[$item['id']] = $item;
}
ne(($byId['offert-laddbox-jarfalla']['updated_by'] ?? '') === $who, 'sidan visar vem som ändrade texten');
ne(($byId['offert-laddbox-jarfalla']['pdf_updated_by'] ?? '') === $who, 'sidan visar vem som bytte PDF');
ne(($byId['offert-laddbox-jarfalla']['can_restore'] ?? false) === true, 'förra textversionen finns');
ne(($byId['offert-laddbox-jarfalla']['pdf_can_restore'] ?? false) === true, 'förra PDF:en finns');

$dryIn = $ingest('ladd-dry', 'info@wix-forms.com', 'laddbox', 'fejk.dry@exempel.se', 'Fejk Dry', '070-000 00 11');
$dry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $dryIn['thread_id'], 'dry' => 1]);
ne(str_contains((string) ($dry['body'] ?? ''), 'NY-OFFERT-TEXT-FEJK'), 'send_quote använder den sparade texten');
ne(!str_contains((string) ($dry['body'] ?? ''), 'Easee Charge Up'), 'send_quote använder inte den gamla texten');
ne(!str_contains((string) ($dry['body'] ?? ''), $email), 'sparad offert har inte kundens e-post');

$before = count($GLOBALS['_SENT']);
$autoIn = $ingest('ladd-auto', 'info@wix-forms.com', 'laddbox', 'fejk.auto@exempel.se', 'Fejk Auto', '070-000 00 12');
$auto = jeab_auto_on_message((string) $autoIn['id']);
$sent = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
ne(($auto['action'] ?? '') === 'quoted' && count($GLOBALS['_SENT']) === $before + 1, 'automatiken skickar via stubben');
ne(str_contains((string) ($sent['body'] ?? ''), 'NY-OFFERT-TEXT-FEJK') && $pdfOf((array) ($sent['attachments'] ?? [])) === $newPdf, 'automatiken använder texten och den nya PDF:en');

jeab_notiser_restore_body('offert-laddbox-jarfalla', $who);
jeab_notiser_restore_pdf('offert-laddbox-jarfalla', $who);
$restored = $ingest('ladd-back', 'info@wix-forms.com', 'laddbox', 'fejk.ater@exempel.se', 'Fejk Ater', '070-000 00 13');
$back = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $restored['thread_id'], 'dry' => 1]);
ne(str_contains((string) ($back['body'] ?? ''), 'Easee Charge Up') && !str_contains((string) ($back['body'] ?? ''), 'NY-OFFERT-TEXT-FEJK'), 'återställd text är den gamla mallen, fick ' . (string) ($back['why'] ?? ''));
$before = count($GLOBALS['_SENT']);
$pdfBackIn = $ingest('ladd-pdf', 'info@wix-forms.com', 'laddbox', 'fejk.pdf@exempel.se', 'Fejk Pdf', '070-000 00 14');
jeab_auto_on_message((string) $pdfBackIn['id']);
$sent = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
ne(count($GLOBALS['_SENT']) === $before + 1 && $pdfOf((array) ($sent['attachments'] ?? [])) === $oldPdf, 'återställd PDF är den gamla filen');

jeab_notiser_save_body('offert-sol-jarfalla-kopt', "Hej\n\nNY-SOL-A-FEJK", $who);
$solIn = $ingest('sol-a', 'leads@leadkit.com', 'sol', 'fejk.sol@exempel.se', 'Fejk Sol', '070-000 00 15');
$sol = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $solIn['thread_id'], 'dry' => 1]);
ne(str_contains((string) ($sol['body'] ?? ''), 'NY-SOL-A-FEJK') && !str_contains((string) ($sol['body'] ?? ''), 'Du har visat intresse'), 'sol A i send_quote är den sparade texten');
$tpl = jeab_offert_template(['variant' => 'kopt', 'name' => $name, 'email' => $email]);
ne(str_contains($tpl['body'], 'NY-SOL-A-FEJK') && str_contains($tpl['body'], 'Hej Fejk,') && !str_contains($tpl['body'], $email), 'Kundtjänst-mallen läser samma text');

$sample = [
    'customer_name' => $name,
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-06-02 09:00:00',
    'ends_at' => '2026-06-02 11:00:00',
    'status' => 'booked',
];
jeab_notiser_save_body('bekraft-laddbox-jarfalla', "{hello}\n\nNY-BEKRAFT-FEJK {nar}", $who);
$letter = jeab_email_laddbox_text($sample);
ne(str_contains($letter, 'NY-BEKRAFT-FEJK') && str_contains($letter, 'Hej Fejk,') && !str_contains($letter, '{nar}'), 'bekräftelsen fyller i tid och namn');
$tz = new DateTimeZone(date_default_timezone_get());
$start = (new DateTimeImmutable('now', $tz))->modify('+5 hours');
$store = jeab_store();
$customer = $store->insertCustomer($name, $start->format('Y-m-d H:i:s'));
$booking = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => $name,
    'email' => $email,
    'phone' => $phone,
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => $start->format('Y-m-d H:i:s'),
    'ends_at' => $start->modify('+2 hours')->format('Y-m-d H:i:s'),
    'status' => 'booked',
    'created_at' => $start->modify('-5 hours')->format('Y-m-d H:i:s'),
    'updated_at' => $start->modify('-8 hours')->format('Y-m-d H:i:s'),
]);
$mailResult = jeab_booking_send_customer_mail($booking);
ne(!empty($mailResult['sent']) && str_contains((string) ($GLOBALS['_MAIL'][0]['text'] ?? ''), 'NY-BEKRAFT-FEJK'), 'bekräftelseutskicket använder den sparade texten');
ne(($GLOBALS['_MAIL'][0]['to'] ?? '') === $email, 'stubben tog emot mottagaren');

jeab_notiser_save_body('sms-laddbox-jarfalla', 'NY-SMS-FEJK {brand}{klocka}', $who);
ne(str_contains(jeab_sms_text($booking), 'NY-SMS-FEJK') && str_contains(jeab_sms_text($booking), 'Demo Energi Järfälla'), 'SMS-texten fyller i bolaget');
$tick = jeab_sms_tick($store);
ne(($tick['sent'] ?? 0) >= 1 && str_contains((string) ($GLOBALS['_SMS'][0]['text'] ?? ''), 'NY-SMS-FEJK'), 'SMS-tickern använder samma text');
ne(!str_contains(json_encode($GLOBALS['_SMS']), 'suresms.com'), 'inget anrop till SureSMS');

jeab_notiser_restore_body('bekraft-laddbox-jarfalla', $who);
jeab_notiser_restore_body('sms-laddbox-jarfalla', $who);
ne(str_contains(jeab_email_laddbox_text($sample), 'sätter upp din laddbox'), 'återställd bekräftelse är originalet');
$restoredSms = jeab_sms_text($booking);
ne((str_contains($restoredSms, 'kommer imorgon') || str_contains($restoredSms, 'kommer idag')) && !str_contains($restoredSms, 'NY-SMS-FEJK'), 'återställt SMS är originalet');

$bad = false;
try {
    jeab_notiser_save_pdf('offert-laddbox-jarfalla', 'inte-en-pdf', $who);
} catch (InvalidArgumentException $e) {
    $bad = str_contains($e->getMessage(), 'PDF');
}
ne($bad, 'annan fil än PDF nekas');
$big = false;
try {
    jeab_notiser_save_pdf('offert-laddbox-jarfalla', '%PDF' . str_repeat('a', jeab_notiser_pdf_max_bytes()), $who);
} catch (InvalidArgumentException $e) {
    $big = str_contains($e->getMessage(), '8 MB');
}
ne($big, 'för stor PDF nekas');
ne(is_file(jeab_auto_pdf_path('laddbox_jarfalla')) && str_starts_with((string) file_get_contents(jeab_auto_pdf_path('laddbox_jarfalla')), '%PDF-1.4 GAMMAL'), 'nekad uppladdning rör inte filen');

echo "notiser edit: ok\n";
