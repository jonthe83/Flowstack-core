<?php

declare(strict_types=1);

/**
 * send_quote för sol och batteri läser text och PDF från Notiser.
 * Saknas text eller PDF skickas inte den gamla mallen.
 * Webb dry=0 köar. Påhittade adresser. Inget mejl.
 */

$tmp = sys_get_temp_dir() . '/jeab-send-quote-notiser-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function nq(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function nq_pdf(string $title): string
{
    $hex = 'FEFF' . strtoupper(bin2hex(mb_convert_encoding($title, 'UTF-16BE', 'UTF-8')));

    return "%PDF-1.4\n1 0 obj\n<< /Title<{$hex}> >>\nendobj\ntrailer\n<< /Info 1 0 R >>\n%%EOF\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;

    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    $GLOBALS['_SENT'][] = $opts;

    return ['ok' => true, 'path' => 'stub', 'id' => 'm1'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn (): array => ['code' => 200, 'body' => ['value' => []]];

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);

$solTitle = 'Demo Energi Järfälla – Solceller';
$batTitle = 'Demo Energi Järfälla – Batteri';
jeab_auto_pdf_save(['key' => 'sol_jarfalla', 'data' => base64_encode(nq_pdf($solTitle))]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode(nq_pdf($batTitle))]);
nq(jeab_notiser_pdf_title(nq_pdf($solTitle)) === $solTitle, 'dokumenttiteln läses ur PDF:en');
nq(jeab_notiser_pdf_filename('sol_jarfalla', nq_pdf($solTitle)) === $solTitle . '.pdf', 'bilagenamnet är titeln');
$repoPdf = static function (string $file): string {
    $text = (string) file_get_contents(dirname(__DIR__) . '/src/solkampanj/' . $file);
    if (!preg_match("/base64_decode\\('([^']+)'\\)/", $text, $m)) {
        return '';
    }
    $raw = base64_decode($m[1], true);

    return is_string($raw) ? $raw : '';
};
$repoSol = $repoPdf('brochure.php');
$repoBat = $repoPdf('battery.php');
nq($repoSol !== '' && jeab_notiser_pdf_filename('sol_jarfalla', $repoSol) === 'Demo Energi Järfälla – Solceller.pdf', 'solbroschyren i repot heter Demo Energi Järfälla – Solceller.pdf');
nq($repoBat !== '' && jeab_notiser_pdf_filename('batteri_jarfalla', $repoBat) === 'Demo Energi Järfälla – Batteri.pdf', 'batteribroschyren i repot heter Demo Energi Järfälla – Batteri.pdf');

$who = 'redaktor@exempel.se';
$solKopt = "Hej,\n\nNY-SOL-KOPT utan gammalt pris.";
$solEget = "Hej,\n\nNY-SOL-EGET utan gammalt pris.";
$batteri = "Hej\n\nNY-BATTERI utan gammalt pris.";
jeab_notiser_save_body('offert-sol-jarfalla-kopt', $solKopt, $who);
jeab_notiser_save_body('offert-sol-jarfalla-eget', $solEget, $who);
jeab_notiser_save_body('offert-batteri-jarfalla', $batteri, $who);

$ingest = static function (string $id, string $from, string $offer, string $addr, string $name): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@nq.test',
        'from' => $from,
        'from_name' => 'Formulär',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Offert',
        'body_text' => "Namn: {$name}\nE-post: {$addr}\nTelefon: 070-000 00 41\nAdress: Fejkgatan 11, 176 70 Järfälla\nProdukt: {$offer}\n",
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'graph_id' => 'graph-' . $id,
        'thread_key' => 'nq-' . $id,
        'preset_labels' => [
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
            'intent' => 'kop', 'offer' => $offer, 'product' => $offer, 'question' => '',
        ],
    ]);
};

$cases = [
    ['sol-kopt', 'leads@leadkit.com', 'sol', 'fejk.solkopt@exempel.se', 'Fejk Kopt', 'kopt', 'offert-sol-jarfalla-kopt', 'NY-SOL-KOPT', $solTitle . '.pdf'],
    ['sol-eget', 'info@wix-forms.com', 'sol', 'fejk.soleget@exempel.se', 'Fejk Eget', 'eget', 'offert-sol-jarfalla-eget', 'NY-SOL-EGET', $solTitle . '.pdf'],
    ['bat-kopt', 'leads@leadkit.com', 'batteri', 'fejk.batkopt@exempel.se', 'Fejk Battkopt', 'kopt', 'offert-batteri-jarfalla', 'NY-BATTERI', $batTitle . '.pdf'],
    ['bat-eget', 'info@wix-forms.com', 'batteri', 'fejk.bateget@exempel.se', 'Fejk Batteget', 'eget', 'offert-batteri-jarfalla', 'NY-BATTERI', $batTitle . '.pdf'],
];
$printed = [];
foreach ($cases as [$id, $from, $offer, $addr, $name, $lead, $notice, $needle, $pdf]) {
    $in = $ingest($id, $from, $offer, $addr, $name);
    nq(!empty($in['ok']), 'ärendet ' . $id . ' tas in');
    $dry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $in['thread_id'], 'dry' => 1]);
    $body = (string) ($dry['body'] ?? '');
    $text = (string) ($dry['text'] ?? '');
    $atts = (array) ($dry['attachments'] ?? []);
    nq(($dry['ok'] ?? false) === true && ($dry['source'] ?? '') === 'notiser' && ($dry['notice'] ?? '') === $notice && ($dry['lead'] ?? '') === $lead, $id . ' dry pekar på Notiser, fick ' . json_encode($dry, JSON_UNESCAPED_UNICODE));
    nq(str_contains($body, $needle) && str_contains($text, $needle), $id . ' använder den sparade texten');
    nq(!str_contains($body, '94 000') && !str_contains($text, '94 000') && !str_contains($body, '46 500'), $id . ' tar inte med det gamla priset');
    nq($atts === [$pdf], $id . ' bilaga är ' . $pdf . ', fick ' . json_encode($atts, JSON_UNESCAPED_UNICODE));
    nq(!in_array('Solceller offert 2608 Demo Energi Järfälla.pdf', $atts, true) && !in_array('Batteri-offert.pdf', $atts, true), $id . ' bifogar inte det gamla filnamnet');
    $printed[$id] = [
        'notice' => $dry['notice'] ?? '',
        'lead' => $dry['lead'] ?? '',
        'source' => $dry['source'] ?? '',
        'subject' => $dry['subject'] ?? '',
        'attachments' => $atts,
        'text' => $text,
    ];
}
nq($GLOBALS['_SENT'] === [], 'dry skickar inget');

$bare = $ingest('sol-tom', 'leads@leadkit.com', 'sol', 'fejk.tom@exempel.se', 'Fejk Tom');
jeab_notiser_save_body('offert-sol-jarfalla-kopt', $solKopt, $who);
jeab_notiser_restore_body('offert-sol-jarfalla-kopt', $who);
jeab_notiser_restore_body('offert-sol-jarfalla-kopt', $who);
$store = jeab_notiser_store_read();
$store['items']['offert-sol-jarfalla-kopt']['body'] = '';
jeab_notiser_store_write($store);
$miss = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $bare['thread_id'], 'dry' => 1]);
$missJson = json_encode($miss, JSON_UNESCAPED_UNICODE);
nq(($miss['ok'] ?? true) === false && ($miss['action'] ?? '') === 'hold' && str_contains((string) ($miss['why'] ?? ''), 'notis-text saknas'), 'saknad text stoppar, fick ' . $missJson);
nq(!str_contains($missJson, '94 000') && !str_contains($missJson, 'Solceller offert 2608'), 'saknad text skickar inte den gamla mallen');

@unlink(jeab_auto_pdf_path('batteri_jarfalla'));
$batBare = $ingest('bat-tom', 'info@wix-forms.com', 'batteri', 'fejk.battom@exempel.se', 'Fejk Battom');
$pdfMiss = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $batBare['thread_id'], 'dry' => 1]);
$pdfJson = json_encode($pdfMiss, JSON_UNESCAPED_UNICODE);
nq(($pdfMiss['action'] ?? '') === 'hold' && str_contains((string) ($pdfMiss['why'] ?? ''), 'notis-PDF saknas'), 'saknad PDF stoppar, fick ' . $pdfJson);
nq(!str_contains($pdfJson, 'Batteri-offert.pdf'), 'saknad PDF faller inte tillbaka på Batteri-offert.pdf');
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode(nq_pdf($batTitle))]);

$named = nq_pdf($batTitle);
jeab_notiser_save_pdf('offert-batteri-jarfalla', $named, $who, 'Uppladdad-batteri.pdf');
$batNamed = $ingest('bat-namn', 'info@wix-forms.com', 'batteri', 'fejk.batnamn@exempel.se', 'Fejk Namn');
$namedDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $batNamed['thread_id'], 'dry' => 1]);
nq(($namedDry['attachments'] ?? []) === ['Uppladdad-batteri.pdf'], 'sparat uppladdningsnamn vinner över titeln, fick ' . json_encode($namedDry['attachments'] ?? [], JSON_UNESCAPED_UNICODE));

$sendCalls = count($GLOBALS['_SENT']);
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
unset($GLOBALS['_JEAB_TICK']);
$webIn = $ingest('web-ko', 'info@wix-forms.com', 'batteri', 'fejk.web@exempel.se', 'Fejk Web');
$queued = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $webIn['thread_id'], 'dry' => 0]);
nq(($queued['ok'] ?? false) === true && ($queued['status'] ?? '') === 'köad' && (int) ($queued['sent'] ?? 1) === 0, 'webb dry=0 svarar köad, fick ' . json_encode($queued, JSON_UNESCAPED_UNICODE));
nq(count($GLOBALS['_SENT']) === $sendCalls, 'webb dry=0 skickar inte');
nq(isset(jeab_inbox_quote_queue_load()[(string) $webIn['thread_id']]), 'tråden ligger i offertkön');
$_POST = ['action' => 'inkorg.send_quote', 'thread' => (string) $webIn['thread_id'], 'dry' => '0'];
$page = jeab_inkorg_action('inkorg.send_quote');
nq($page === 'Offerten ligger redan i kön.', 'sidan säger att offerten redan ligger i kön, fick ' . $page);

echo "DRY\n" . json_encode($printed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
echo "send quote notiser ok\n";
