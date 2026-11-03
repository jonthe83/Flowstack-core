<?php

declare(strict_types=1);

/**
 * Notiser till slutkund. Påhittade mallar, bilagor och loggar.
 * Ingen riktig kund, inget utskick, inget sparande.
 */

$tmp = sys_get_temp_dir() . '/jeab-notiser-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa testkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';

require dirname(__DIR__) . '/src/bootstrap.php';

function nt(bool $ok, string $message): void
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

$tz = new DateTimeZone(date_default_timezone_get());
$recent = (new DateTimeImmutable('now', $tz))->modify('-2 days')->format('Y-m-d H:i:s');
$old = (new DateTimeImmutable('now', $tz))->modify('-40 days')->format('Y-m-d H:i:s');
$customerEmail = 'hemlig.kund@exempel.test';
$customerName = 'Hemlig Testkund';
$customerPhone = '+46701112233';
$smsSecret = 'suresms-hemlis-abc';
$graphSecret = 'graph-hemlis-abc';

$settingsPath = jeab_inkorg_dir() . '/settings.json';
$settings = [
    'helpscout_companies' => [],
    'graph_person_mail' => true,
    'quote_templates' => [
        'laddbox_jarfalla' => "Hej\n\nFejkad elbilsmall.",
        'batteri_jarfalla' => "Hej\n\nFejkad batterimall.",
        'laddbox_malmo' => '',
        'batteri_malmo' => "Hej\n\nFejkad malmöbatteri.",
        'sol_jarfalla' => '',
        'sol_malmo' => '',
    ],
];
file_put_contents($settingsPath, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$pdfPath = jeab_auto_pdf_path('laddbox_jarfalla');
if (!is_dir(dirname($pdfPath))) {
    mkdir(dirname($pdfPath), 0700, true);
}
file_put_contents($pdfPath, "%PDF-1.4\n%fejkad-offert\n");
$secretPath = jeab_graph_secret_path('m365.json');
if (!is_dir(dirname($secretPath))) {
    mkdir(dirname($secretPath), 0700, true);
}
file_put_contents($secretPath, json_encode([
    'tenant_id' => 'tenant-fejk',
    'client_id' => 'client-fejk',
    'client_secret' => $graphSecret,
]));
file_put_contents(jeab_suresms_path(), json_encode([
    'login' => 'APIKey',
    'password' => $smsSecret,
    'from' => 'JEAB',
    'from_malmo' => '+46700000099',
]));

$threads = [
    'threads' => [
        't-ladd' => [
            'status' => 'closed',
            'tags' => [],
            'drafts' => [
                [
                    'id' => 'n-old',
                    'kind' => 'note',
                    'body' => 'Offert (Elbilsladdare) skickad automatiskt från saljare4@jarfalla.example.test till ' . $customerEmail . '.',
                    'created_at' => $old,
                ],
                [
                    'id' => 'n-new',
                    'kind' => 'note',
                    'body' => 'Offert (Elbilsladdare) skickad automatiskt från saljare4@jarfalla.example.test till ' . $customerEmail . '.',
                    'created_at' => $recent,
                ],
            ],
            'quote_sent' => ['source' => 'Inkorg', 'date' => substr($recent, 0, 10), 'at' => $recent],
        ],
        't-batt' => [
            'status' => 'open',
            'tags' => [],
            'drafts' => [
                [
                    'id' => 'n-fail',
                    'kind' => 'note',
                    'body' => 'Offert skickades inte automatiskt: utskicket misslyckades (nej).',
                    'created_at' => $recent,
                ],
            ],
        ],
    ],
];
file_put_contents(jeab_inkorg_dir() . '/threads.json', json_encode($threads, JSON_UNESCAPED_UNICODE));
file_put_contents(jeab_inkorg_dir() . '/messages.json', json_encode([
    'messages' => [
        [
            'id' => 'm-ladd',
            'thread_id' => 't-ladd',
            'from' => 'formular@testform.test',
            'to' => 'kontakt@jarfalla.example.test',
            'subject' => 'Fråga',
            'body' => 'Hej',
            'received_at' => $recent,
            'company' => 'jarfalla',
            'labels' => ['offer' => 'laddbox'],
            'source' => 'test',
        ],
        [
            'id' => 'm-batt',
            'thread_id' => 't-batt',
            'from' => 'formular@testform.test',
            'to' => 'kontakt@jarfalla.example.test',
            'subject' => 'Batteri',
            'body' => 'Hej',
            'received_at' => $recent,
            'company' => 'jarfalla',
            'labels' => ['offer' => 'batteri'],
            'source' => 'test',
        ],
    ],
], JSON_UNESCAPED_UNICODE));

$store = jeab_store();
$customer = $store->insertCustomer($customerName, $recent);
$booking = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => $customerName,
    'email' => $customerEmail,
    'phone' => $customerPhone,
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => $recent,
    'ends_at' => $recent,
    'status' => 'booked',
    'created_at' => $old,
    'updated_at' => $old,
]);
file_put_contents(jeab_sms_sent_path(), json_encode([
    (string) $booking['id'] => [
        'starts_at' => $recent,
        'email' => true,
        'email_at' => $recent,
        'sms' => true,
        'sms_at' => $recent,
    ],
], JSON_UNESCAPED_UNICODE));
file_put_contents($GLOBALS['_JEAB_CALENDAR_LOG_PATH'], json_encode([
    'entries' => [[
        'id' => 1,
        'type' => 'mail',
        'at' => $recent,
        'booking_id' => (int) $booking['id'],
        'starts_at' => $recent,
        'title' => $customerName,
    ]],
    'backfilled' => false,
], JSON_UNESCAPED_UNICODE));

$before = hash_file('sha256', $settingsPath);
$list = jeab_notiser_list();
nt(($list['ok'] ?? false) === true, 'listan svarar ok');
nt(hash_file('sha256', $settingsPath) === $before, 'listan skriver inte om settings.json');

$byId = [];
foreach ($list['items'] as $item) {
    $byId[$item['id']] = $item;
}

$ladd = $byId['offert-laddbox-jarfalla'] ?? null;
nt(is_array($ladd), 'elbilsmallen från settings finns');
nt(($ladd['body'] ?? '') === "Hej\n\nFejkad elbilsmall.", 'brödtexten är samma text som utskicket läser');
nt(($ladd['sends_30d'] ?? -1) === 1, 'bara utskicket inom 30 dagar räknas, fick ' . ($ladd['sends_30d'] ?? ''));
nt(($ladd['status'] ?? '') === 'Aktiv', 'använd mall med bilaga och avsändare är Aktiv, fick ' . ($ladd['status'] ?? ''));
nt(str_contains((string) ($ladd['sender'] ?? ''), 'saljare4@jarfalla.example.test'), 'avsändaren är Elins brevlåda');
nt(($ladd['attachments'][0]['missing'] ?? true) === false, 'elbilens PDF räknas som bilaga');
nt((int) ($ladd['attachments'][0]['bytes'] ?? 0) === filesize($pdfPath), 'storleken är filens storlek');

$batt = $byId['offert-batteri-jarfalla'] ?? null;
nt(is_array($batt), 'batterimallen finns');
nt(str_contains((string) ($batt['problem'] ?? ''), 'bilaga saknas') && ($batt['attachments'][0]['missing'] ?? false) === true, 'batteri utan uppladdad PDF räknas inte som repo-filen, fick ' . ($batt['problem'] ?? ''));
nt(str_contains((string) ($batt['problem'] ?? ''), 'senaste utskicket misslyckades'), 'misslyckad anteckning syns');
nt(($batt['status'] ?? '') === 'Problem', 'batteriraden är Problem');

$sol = $byId['offert-sol-jarfalla'] ?? null;
nt(is_array($sol), 'tom Järfälla-sol är en egen rad');
nt(str_contains((string) ($sol['problem'] ?? ''), 'mall saknas'), 'tom solmall är mall saknas, fick ' . ($sol['problem'] ?? ''));
nt(!isset($byId['offert-sol-jarfalla-kopt']), 'tom solmall använder inte mall A');

$malmo = $byId['offert-laddbox-malmo'] ?? null;
nt(is_array($malmo), 'tom Malmö-elbil fylls av samma funktion som utskicket');
nt(($malmo['body'] ?? '') === (string) jeab_auto_settings()['templates']['laddbox_malmo'], 'Malmö-texten är settings-texten');
nt(($malmo['body'] ?? '') === jeab_auto_malmo_template('laddbox_malmo'), 'ifyllnaden är jeab_auto_malmo_template');
nt(str_contains((string) ($malmo['source'] ?? ''), 'jeab_auto_malmo_template'), 'källan säger att mallen fylls i');
foreach (['offert-batteri-malmo', 'offert-laddbox-malmo', 'offert-sol-malmo'] as $nid) {
    $kimRow = $byId[$nid] ?? null;
    nt(is_array($kimRow) && ($kimRow['sender'] ?? '') === 'Kim, Demo Energi Malmö · saljare.syd@malmo.example.test', $nid . ' avsändare är Kim, fick ' . (string) (is_array($kimRow) ? ($kimRow['sender'] ?? '') : ''));
}
$solMalmo = $byId['offert-sol-malmo'] ?? null;
nt(is_array($solMalmo) && str_contains((string) ($solMalmo['trigger'] ?? ''), 'Utskicket sker inte automatiskt'), 'sol i Malmö skickas inte automatiskt');
nt(jeab_auto_quote_region_block(['brand' => 'malmo', 'messages' => []], [], [], 'sol') === 'Malmö/Skåne', 'sol i Malmö hålls av regionregeln');

$smsSol = $byId['sms-sol-malmo'] ?? null;
nt(is_array($smsSol) && str_contains((string) ($smsSol['problem'] ?? ''), 'mall saknas'), 'Malmö-sol utan SMS är mall saknas');

$mail = $byId['bekraft-laddbox-jarfalla'] ?? null;
$sms = $byId['sms-laddbox-jarfalla'] ?? null;
nt(is_array($mail) && ($mail['sends_30d'] ?? 0) === 1, 'bekräftelsemejlet räknas en gång');
nt(is_array($sms) && ($sms['sends_30d'] ?? 0) === 1 && ($sms['sender'] ?? '') === 'JEAB', 'SMS-räknaren och avsändaren kommer från SureSMS-config');
nt(str_contains((string) ($mail['body'] ?? ''), 'sätter upp din laddbox'), 'bekräftelsen är jeab_email_laddbox_text');

$service = $byId['bekraft-service-jarfalla'] ?? null;
nt(is_array($service) && str_contains((string) ($service['body'] ?? ''), 'Bokad tid:'), 'service använder den gemensamma bokningstexten');
nt(str_contains((string) ($service['trigger'] ?? ''), 'Ingen egen servicemall'), 'service säger att egen mall saknas i villkoret');

$rules = $byId['svarsregler'] ?? null;
nt(is_array($rules) && ($rules['source'] ?? '') === 'config/svarsregler.php', 'svarsreglerna läses från config');
nt(str_contains((string) ($rules['edit'] ?? ''), 'redigeras här'), 'svarsreglerna redigeras på sidan');

$blob = json_encode($list, JSON_UNESCAPED_UNICODE);
nt(!str_contains((string) $blob, $customerEmail), 'kundens e-post lämnar inte listan');
nt(!str_contains((string) $blob, $customerName), 'kundens namn lämnar inte listan');
nt(!str_contains((string) $blob, $customerPhone), 'kundens telefon lämnar inte listan');
nt(!str_contains((string) $blob, $smsSecret), 'SureSMS-nyckeln lämnar inte listan');
nt(!str_contains((string) $blob, $graphSecret), 'Graph-hemligheten lämnar inte listan');
nt(!str_contains((string) $blob, $tmp), 'ingen testkatalog i svaret');
nt(!str_contains((string) $blob, 'formular@testform.test'), 'mejladressen i ärendet lämnar inte listan');

$settings['quote_templates']['laddbox_jarfalla'] = "Hej\n\nÄndrad fejktext.";
file_put_contents($settingsPath, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$again = jeab_notiser_list();
$againBody = '';
foreach ($again['items'] as $item) {
    if ($item['id'] === 'offert-laddbox-jarfalla') {
        $againBody = (string) $item['body'];
    }
}
nt($againBody === "Hej\n\nÄndrad fejktext.", 'ändrad mall syns direkt från samma fil');

$settings['quote_templates']['sol_jarfalla'] = (string) jeab_auto_quote_seed()['sol_jarfalla'];
file_put_contents($settingsPath, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$split = [];
foreach (jeab_notiser_list()['items'] as $item) {
    $split[$item['id']] = $item;
}
nt(isset($split['offert-sol-jarfalla-kopt']) && isset($split['offert-sol-jarfalla-eget']), 'platshållaren visar mall A och B');
nt(str_contains((string) $split['offert-sol-jarfalla-kopt']['body'], 'Du har visat intresse'), 'mall A är jeab_offert_mall_body');
nt(str_contains((string) $split['offert-sol-jarfalla-eget']['body'], '1. Du svarar'), 'mall B är jeab_offert_mall_body');
nt(!str_contains(strtolower((string) $split['offert-sol-jarfalla-kopt']['source']), 'nylas'), 'Notiser visar inte Nylas som källa');
nt(!str_contains(strtolower((string) $split['offert-sol-jarfalla-kopt']['edit']), 'nylas'), 'Notiser visar inte nylas_drafts.php');
nt(!isset($split['offert-sol-jarfalla']), 'platshållaren är inte en egen tom rad');

nt(jeab_notiser_file_key('../secrets/m365.json') === '', 'filsökvägen tar inte en sökväg');
nt(jeab_notiser_file_key('laddbox_jarfalla') === 'laddbox_jarfalla', 'känd bilagenyckel släpps igenom');
nt(jeab_notiser_file_key('team_jarfalla') === 'team_jarfalla', 'teambilden är en känd nyckel');

$rows = jeab_notiser_catalog();
$filter = ['co' => '', 'ch' => '', 'st' => ''];
$visible = jeab_notiser_filter($rows, $filter);
$counts = jeab_notiser_counts($rows);
$html = require dirname(__DIR__) . '/src/pages/notiser.php';
nt(str_contains($html, 'Utskick') && str_contains($html, 'Notiser till slutkund') && str_contains($html, 'flik=notiser'), 'sidan heter Utskick och visar notiserna');
nt(str_contains($html, 'Ändrad fejktext'), 'sidan visar den ändrade mallen');
nt(!str_contains($html, 'Fejkad elbilsmall'), 'sidan visar inte den gamla mallen');
nt(str_contains($html, 'mall A, köpt lead'), 'sidan visar mall A');
nt(str_contains($html, 'crm-top') && str_contains($html, 'smart-tile') && str_contains($html, 'chip-primary'), 'sidan använder Påminnelsernas delar');
nt(!str_contains($html, 'apple-list') && !str_contains($html, 'crm-app') && !str_contains($html, 'is-today'), 'sidan lämnar Påminnelsernas skript ifred');
nt(str_contains($html, 'notiser.save') && str_contains($html, 'name="body"'), 'sidan har ett sparformulär per mall');
nt(!str_contains($html, $customerEmail) && !str_contains($html, $smsSecret), 'sidan visar varken kund eller nyckel');

require_once dirname(__DIR__) . '/src/layout.php';
$nav = jeab_module_nav('notiser');
nt(!str_contains($nav, 'p=notiser'), 'notiser ligger inte i huvudmenyn');
nt(str_contains($nav, 'Övrigt') && str_contains($nav, 'is-on'), 'Övrigt är markerat');
$public = jeab_public_links();
nt(($public[0][0] ?? '') === 'Utskick' && str_contains((string) ($public[0][1] ?? ''), 'p=utskick'), 'Utskick är första valet under Övrigt');
$labels = array_map(static fn(array $item): string => (string) ($item[0] ?? ''), $public);
nt(!in_array('Inkorg', $labels, true), 'Inkorg finns inte under Övrigt');
foreach (['laddbox', 'batteri', 'sol', 'service'] as $job) {
    foreach (['jarfalla', 'malmo'] as $company) {
        $prefix = $job === 'sol' && $company === 'jarfalla' ? null : 'offert-' . $job . '-' . $company;
        if ($job !== 'service' && $prefix !== null) {
            nt(isset($byId[$prefix]), 'offertmall ' . $prefix);
        }
        if ($job === 'sol' && $company === 'jarfalla') {
            nt(isset($split['offert-sol-jarfalla-kopt']) && isset($split['offert-sol-jarfalla-eget']), 'sol Järfälla är mall A och B');
        }
        nt(isset($byId['bekraft-' . $job . '-' . $company]), 'bekräftelse ' . $job . ' ' . $company);
        nt(isset($byId['sms-' . $job . '-' . $company]), 'sms ' . $job . ' ' . $company);
    }
}
nt(str_contains($html, '>Av<') && !str_contains($html, 'Passiv'), 'statusen heter Av');
$linksHtml = require dirname(__DIR__) . '/src/pages/links.php';
nt(str_contains($linksHtml, '>Utskick<') && str_contains($linksHtml, 'p=utskick') && !str_contains($linksHtml, 'p=notiser') && !str_contains($linksHtml, 'p=offertutskick'), 'Övrigt-sidan visar en Utskick-länk');
nt(!str_contains($linksHtml, 'p=inkorg'), 'Övrigt-sidan har ingen Inkorg-länk');
nt(strpos($linksHtml, 'p=utskick') < strpos($linksHtml, 'p=kontakter'), 'Utskick ligger före Kontakter');
$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
nt(str_contains($hook, "notiser.list") && str_contains($hook, 'jeab_notiser_list'), 'hooken notiser.list finns');

echo "notiser: ok\n";
