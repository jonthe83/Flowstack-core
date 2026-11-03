<?php

declare(strict_types=1);

/**
 * Senaste utskick på Utskick-raden.
 * Tid hämtas från anteckning, quote_sent, utgående bilaga och notis_sends.
 * Testkopia räknas inte som kundutskick. Ingenting skickas.
 */

$tmp = sys_get_temp_dir() . '/jeab-utskick-senast-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa testkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
$GLOBALS['JEAB_GRAPH_SEND'] = static function (): array {
    fwrite(STDERR, "FAIL graph skickades\n");
    exit(1);
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = $GLOBALS['JEAB_GRAPH_SEND'];
$GLOBALS['JEAB_SMS_SEND'] = $GLOBALS['JEAB_GRAPH_SEND'];

require dirname(__DIR__) . '/src/bootstrap.php';

function us_nt(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

register_shutdown_function(static function () use ($tmp): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($tmp);
});

file_put_contents(jeab_inkorg_dir() . '/settings.json', json_encode([
    'quote_templates' => [
        'laddbox_jarfalla' => "Hej\n\nFejkad elbilsmall.",
        'batteri_jarfalla' => "Hej\n\nFejkad batterimall.",
        'laddbox_malmo' => "Hej\n\nFejkad malmöelbil.",
        'batteri_malmo' => "Hej\n\nFejkad malmöbatteri.",
        'sol_jarfalla' => '',
        'sol_malmo' => '',
    ],
], JSON_UNESCAPED_UNICODE));
$pdfPath = jeab_auto_pdf_path('laddbox_jarfalla');
if (!is_dir(dirname($pdfPath))) {
    mkdir(dirname($pdfPath), 0700, true);
}
file_put_contents($pdfPath, "%PDF-1.4\n%fejk\n");
file_put_contents($GLOBALS['_JEAB_CALENDAR_LOG_PATH'], json_encode(['entries' => [], 'backfilled' => false]));

$whenNote = '2026-10-20 15:04:00';
$whenPdf = '2026-10-21 09:15:00';
$whenSol = '2026-10-22 11:00:00';
$whenMove = '2026-10-23 08:30:00';
$whenFail = '2026-10-24 16:45:00';

file_put_contents(jeab_inkorg_dir() . '/threads.json', json_encode(['threads' => [
    't-notime' => [
        'status' => 'closed',
        'tags' => [],
        'quote_sent' => ['source' => 'Inkorg', 'date' => '2026-10-20 15:04', 'at' => $whenNote, 'product' => 'laddbox'],
        'drafts' => [[
            'id' => 'n-notime',
            'kind' => 'note',
            'body' => 'Offert (Elbilsladdare) skickad automatiskt från saljare4@jarfalla.example.test.',
        ], [
            'id' => 'n-svar',
            'kind' => 'note',
            'body' => 'Kort svar: Priset står i offerten.',
            'created_at' => '2026-10-20T15:10:00+00:00',
        ]],
    ],
    't-pdf' => [
        'status' => 'closed',
        'tags' => [],
        'drafts' => [],
    ],
    't-sol' => [
        'status' => 'closed',
        'tags' => [],
        'drafts' => [],
    ],
    't-fail' => [
        'status' => 'open',
        'brand' => 'malmo',
        'tags' => [],
        'drafts' => [[
            'id' => 'n-fail',
            'kind' => 'note',
            'body' => 'Offert skickades inte automatiskt: testorsak utan adress. Ärendet lämnas till er.',
            'created_at' => $whenFail,
        ]],
    ],
]], JSON_UNESCAPED_UNICODE));
file_put_contents(jeab_inkorg_dir() . '/messages.json', json_encode(['messages' => [
    [
        'id' => 'm-notime',
        'thread_id' => 't-notime',
        'from' => 'formular@testform.test',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Elbil',
        'body' => 'Hej',
        'received_at' => $whenNote,
        'labels' => ['offer' => 'laddbox'],
        'source' => 'test',
    ],
    [
        'id' => 'm-pdf',
        'thread_id' => 't-pdf',
        'from' => 'saljare3@jarfalla.example.test',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Offert batteri',
        'body' => "Hej\n\n[Bilaga: Batteri-offert.pdf]",
        'received_at' => $whenPdf,
        'source' => 'outbound-jarfalla',
        'kind' => 'offert',
    ],
    [
        'id' => 'm-sol',
        'thread_id' => 't-sol',
        'from' => 'demo@example.test',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Anteckning',
        'body' => 'Offert (Solceller) skickad automatiskt.',
        'received_at' => $whenSol,
        'internal_note' => true,
        'labels' => ['offer' => 'sol'],
        'source' => 'helpscout',
    ],
    [
        'id' => 'm-fail',
        'thread_id' => 't-fail',
        'from' => 'formular@testform.test',
        'to' => 'kontakt@malmo.example.test',
        'subject' => 'Elbil Malmö',
        'body' => 'Hej',
        'received_at' => $whenFail,
        'labels' => ['offer' => 'laddbox'],
        'source' => 'test',
    ],
]], JSON_UNESCAPED_UNICODE));

$store = jeab_store();
$customer = $store->insertCustomer('Flytt Test', $whenMove);
$booking = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Flytt Test',
    'email' => 'flytt@exempel.test',
    'phone' => '+46701112233',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => $whenMove,
    'ends_at' => $whenMove,
    'status' => 'booked',
    'created_at' => $whenMove,
    'updated_at' => $whenMove,
]);
file_put_contents(jeab_sms_sent_path(), json_encode([
    (string) $booking['id'] => [
        'starts_at' => $whenMove,
        'move_email' => true,
        'move_email_at' => $whenMove,
    ],
], JSON_UNESCAPED_UNICODE));

$byId = [];
foreach (jeab_notiser_list()['items'] as $item) {
    $byId[$item['id']] = $item;
}

us_nt(($byId['offert-laddbox-jarfalla']['last_used'] ?? '') === '2026-10-20 15:04', 'anteckning utan tid tar quote_sent, fick ' . ($byId['offert-laddbox-jarfalla']['last_used'] ?? ''));
us_nt(($byId['svarsregler']['last_used'] ?? '') === '2026-10-20 17:10', 'kort svar blir Stockholm-tid, fick ' . ($byId['svarsregler']['last_used'] ?? ''));
us_nt(jeab_notiser_stamp(jeab_notiser_moment('2026-10-28T13:24:51+00:00')) === '2026-10-28 14:24', 'UTC blir Stockholm');
us_nt(($byId['offert-batteri-jarfalla']['last_used'] ?? '') === '2026-10-21 09:15', 'utgående bilaga ger batteri-radens tid, fick ' . ($byId['offert-batteri-jarfalla']['last_used'] ?? ''));
us_nt(($byId['offert-sol-jarfalla']['last_used'] ?? '') === '2026-10-22 11:00', 'anteckning som mejl ger solradens tid, fick ' . ($byId['offert-sol-jarfalla']['last_used'] ?? ''));
us_nt(($byId['flytt-jarfalla']['last_used'] ?? '') === '2026-10-23 08:30', 'flyttmejl syns på flyttraden, fick ' . ($byId['flytt-jarfalla']['last_used'] ?? ''));
us_nt(str_contains((string) ($byId['offert-laddbox-malmo']['problem'] ?? ''), 'testorsak utan adress'), 'misslyckad rad visar orsaken, fick ' . ($byId['offert-laddbox-malmo']['problem'] ?? ''));
us_nt(($byId['offert-laddbox-malmo']['status'] ?? '') === 'Problem', 'misslyckad rad är Problem');
us_nt(($byId['offert-laddbox-malmo']['last_used'] ?? '') === '', 'misslyckat försök är inte ett utskick');

$beforeSends = (int) ($byId['offert-batteri-malmo']['sends_30d'] ?? 0);
$beforeUsed = (string) ($byId['offert-batteri-malmo']['last_used'] ?? '');
jeab_notiser_record_send('offert-batteri-malmo', true, true, '');
$byId = [];
foreach (jeab_notiser_list()['items'] as $item) {
    $byId[$item['id']] = $item;
}
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm'));
$gotTest = (string) ($byId['offert-batteri-malmo']['last_test'] ?? '');
us_nt($gotTest === $now->format('Y-m-d H:i') || $gotTest === $now->modify('-1 minute')->format('Y-m-d H:i'), 'testkopia visas för sig, fick ' . $gotTest);
us_nt(($byId['offert-batteri-malmo']['last_used'] ?? '') === $beforeUsed, 'testkopia ändrar inte senaste kundutskick');
us_nt((int) ($byId['offert-batteri-malmo']['sends_30d'] ?? -1) === $beforeSends, 'testkopia räknas inte i 30 dagar');

$on = $byId['offert-batteri-malmo'];
us_nt(!empty($on['enabled']) && ($on['status'] ?? '') !== 'Av', 'påslagen rad säger inte Av, fick ' . ($on['status'] ?? ''));
jeab_notiser_set_enabled('offert-batteri-malmo', false, 'test');
$off = [];
foreach (jeab_notiser_catalog() as $item) {
    if (($item['id'] ?? '') === 'offert-batteri-malmo') {
        $off = $item;
    }
    if (($item['id'] ?? '') === 'offert-laddbox-jarfalla') {
        $on = $item;
    }
}
us_nt(empty($off['enabled']) && ($off['status'] ?? '') === 'Av', 'avstängd rad och knappens läge är Av');
us_nt(!empty($on['enabled']) && ($on['status'] ?? '') !== 'Av', 'påslagen rad är inte Av');

$rows = [$off, $on];
$visible = $rows;
$counts = jeab_notiser_counts($rows);
$filter = ['co' => '', 'ch' => '', 'st' => '', 'flik' => 'notiser'];
$html = require dirname(__DIR__) . '/src/pages/notiser.php';
us_nt(str_contains($html, 'notis-why') && str_contains($html, 'testorsak utan adress') === false, 'orsaken visas bara när status är Problem');
$fail = null;
foreach (jeab_notiser_catalog() as $item) {
    if (($item['id'] ?? '') === 'offert-laddbox-malmo') {
        $fail = $item;
    }
}
$visible = [$fail];
$rows = $visible;
$counts = jeab_notiser_counts($rows);
$htmlFail = require dirname(__DIR__) . '/src/pages/notiser.php';
us_nt(str_contains($htmlFail, 'class="notis-why"') && str_contains($htmlFail, 'testorsak utan adress'), 'Problem visar orsaken i raden');
us_nt(str_contains($htmlFail, '>På<') && str_contains($htmlFail, '>Problem<'), 'påslagen problemrad har läge På');
$offAt = strpos($html, 'value="offert-batteri-malmo"');
$onAt = strpos($html, 'value="offert-laddbox-jarfalla"');
$offChunk = $offAt === false ? '' : substr($html, $offAt, 400);
$onChunk = $onAt === false ? '' : substr($html, $onAt, 400);
us_nt(str_contains($offChunk, '>Av<') && !str_contains($offChunk, '>På<'), 'avstängd radens knapp visar Av');
us_nt(str_contains($onChunk, '>På<') && !str_contains($onChunk, '>Av<'), 'påslagen radens knapp visar På');
us_nt(str_contains($html, 'Senast 2026-10-20 15:04') && str_contains($htmlFail, 'Senast aldrig'), 'raden visar klockslag eller aldrig');

echo "utskick-senast: ok\n";
