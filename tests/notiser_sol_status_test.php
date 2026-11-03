<?php

declare(strict_types=1);

/**
 * Solmall A/B: en senare lyckad offert nollställer en gammal felanteckning,
 * även när sista mejlet är den utgående kopian utan lead.
 * Självtest räknas som lyckat men inte i sends_30d.
 * Ingen riktig kund, inget utskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-notiser-sol-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa testkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (): array {
    fwrite(STDERR, "FAIL mejl skickades\n");
    exit(1);
};
$GLOBALS['JEAB_SMS_SEND'] = static function (): array {
    fwrite(STDERR, "FAIL sms skickades\n");
    exit(1);
};
$GLOBALS['JEAB_GRAPH_SEND'] = static function (): array {
    fwrite(STDERR, "FAIL graph skickades\n");
    exit(1);
};

require dirname(__DIR__) . '/src/bootstrap.php';

function sol_nt(bool $ok, string $message): void
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

$failAt = '2026-10-15 00:40:00';
$okAt = '2026-10-18 17:43:00';
$okStamp = '2026-10-18 17:43';
$fakeTo = 'fejk.lead@example.test';

$settingsPath = jeab_inkorg_dir() . '/settings.json';
$settings = [
    'helpscout_companies' => [],
    'graph_person_mail' => true,
    'quote_templates' => jeab_auto_quote_seed(),
];
file_put_contents($settingsPath, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$pdfPath = jeab_auto_pdf_path('sol_jarfalla');
if (!is_dir(dirname($pdfPath))) {
    mkdir(dirname($pdfPath), 0700, true);
}
file_put_contents($pdfPath, "%PDF-1.4\n%fejkad-sol\n");
$graphSecret = 'graph-hemlis-sol';
$secretPath = jeab_graph_secret_path('m365.json');
if (!is_dir(dirname($secretPath))) {
    mkdir(dirname($secretPath), 0700, true);
}
file_put_contents($secretPath, json_encode([
    'tenant_id' => 'tenant-fejk',
    'client_id' => 'client-fejk',
    'client_secret' => $graphSecret,
]));

/**
 * @param array<string, array<string, mixed>> $threads
 * @param list<array<string, mixed>> $messages
 */
function sol_write(array $threads, array $messages): void
{
    file_put_contents(jeab_inkorg_dir() . '/threads.json', json_encode(['threads' => $threads], JSON_UNESCAPED_UNICODE));
    file_put_contents(jeab_inkorg_dir() . '/messages.json', json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE));
}

function sol_row(string $id): array
{
    foreach (jeab_notiser_list()['items'] as $item) {
        if (($item['id'] ?? '') === $id) {
            return $item;
        }
    }
    return [];
}

function sol_inbound(string $id, string $thread, string $lead, string $at, bool $test = false): array
{
    return [
        'id' => $id,
        'thread_id' => $thread,
        'from' => 'fejk.form@example.test',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Sol',
        'body' => 'Hej',
        'received_at' => $at,
        'company' => 'jarfalla',
        'labels' => ['offer' => 'sol', 'lead' => $lead],
        'source' => 'test',
        'test' => $test,
    ];
}

function sol_outbound(string $id, string $thread, string $at): array
{
    return [
        'id' => $id,
        'thread_id' => $thread,
        'from' => 'saljare4@jarfalla.example.test',
        'to' => 'fejk.lead@example.test',
        'subject' => 'Offert',
        'body' => 'Hej',
        'received_at' => $at,
        'graph_mailbox' => 'saljare4@jarfalla.example.test',
        'labels' => ['type' => 'Information', 'action' => 'arkivera', 'source' => 'system'],
        'source' => 'outbound-jarfalla',
    ];
}

$successNote = 'Offert (Solceller med batteri) skickad automatiskt från saljare4@jarfalla.example.test till ' . $fakeTo . '.';
$failNote = 'Offert skickades inte automatiskt: utskicket misslyckades (nej). Ärendet lämnas till er.';

sol_write([
    't-eget' => [
        'status' => 'closed',
        'tags' => [],
        'drafts' => [
            ['id' => 'n-eget-fail', 'kind' => 'note', 'body' => $failNote, 'created_at' => $failAt],
            ['id' => 'n-eget-ok', 'kind' => 'note', 'body' => $successNote, 'created_at' => $okAt],
        ],
    ],
    't-kopt' => [
        'status' => 'closed',
        'tags' => [],
        'drafts' => [
            ['id' => 'n-kopt-fail', 'kind' => 'note', 'body' => $failNote, 'created_at' => $failAt],
            ['id' => 'n-kopt-ok', 'kind' => 'note', 'body' => $successNote, 'created_at' => $okAt],
        ],
    ],
], [
    sol_inbound('m-eget', 't-eget', 'eget', '2026-10-15 00:30:00'),
    sol_outbound('m-eget-ut', 't-eget', $okAt),
    sol_inbound('m-kopt', 't-kopt', 'kopt', '2026-10-15 00:30:00'),
    sol_outbound('m-kopt-ut', 't-kopt', $okAt),
]);

foreach (['offert-sol-jarfalla-eget' => 'mall B', 'offert-sol-jarfalla-kopt' => 'mall A'] as $id => $label) {
    $row = sol_row($id);
    sol_nt($row !== [], $label . ' finns');
    sol_nt(($row['status'] ?? '') === 'Aktiv', $label . ' är Aktiv efter senare lyckat utskick, fick ' . ($row['status'] ?? '') . ' ' . ($row['problem'] ?? ''));
    sol_nt(($row['last_used'] ?? '') === $okStamp, $label . ' last_used är det senare utskicket, fick ' . ($row['last_used'] ?? ''));
    sol_nt((int) ($row['sends_30d'] ?? 0) === 1, $label . ' räknar det lyckade utskicket, fick ' . ($row['sends_30d'] ?? ''));
    sol_nt(!str_contains((string) ($row['problem'] ?? ''), 'misslyckades'), $label . ' har ingen kvarvarande felanteckning, fick ' . ($row['problem'] ?? ''));
}

$blob = json_encode(jeab_notiser_list(), JSON_UNESCAPED_UNICODE);
sol_nt(!str_contains((string) $blob, $fakeTo), 'fejkad mottagare lämnar inte listan');
sol_nt(!str_contains((string) $blob, 'fejk.form@example.test'), 'fejkad avsändare lämnar inte listan');
sol_nt(!str_contains((string) $blob, $graphSecret), 'Graph-hemligheten lämnar inte listan');

sol_write([
    'autotest-eget' => [
        'status' => 'closed',
        'tags' => [],
        'drafts' => [
            ['id' => 'n-test-fail', 'kind' => 'note', 'body' => $failNote, 'created_at' => $failAt],
            ['id' => 'n-test-ok', 'kind' => 'note', 'body' => $successNote, 'created_at' => $okAt],
        ],
    ],
], [
    sol_inbound('m-test', 'autotest-eget', 'eget', '2026-10-15 00:30:00', true),
    sol_outbound('m-test-ut', 'autotest-eget', $okAt),
]);
$testRow = sol_row('offert-sol-jarfalla-eget');
sol_nt(($testRow['last_used'] ?? '') === '', 'självtest är inte senaste kundutskick, fick ' . ($testRow['last_used'] ?? ''));
sol_nt(($testRow['last_test'] ?? '') === $okStamp, 'självtest visas som testkopia, fick ' . ($testRow['last_test'] ?? ''));
sol_nt((int) ($testRow['sends_30d'] ?? -1) === 0, 'självtest räknas inte i sends_30d, fick ' . ($testRow['sends_30d'] ?? ''));
sol_nt(($testRow['status'] ?? '') === 'Aktiv', 'självtest med påslagen rad är Aktiv, fick ' . ($testRow['status'] ?? '') . ' ' . ($testRow['problem'] ?? ''));
sol_nt(!str_contains((string) ($testRow['problem'] ?? ''), 'misslyckades'), 'självtest tar bort den gamla felanteckningen');

$why = 'kundens e-post saknas';
sol_write([
    't-varfor' => [
        'status' => 'open',
        'tags' => [],
        'drafts' => [[
            'id' => 'n-varfor',
            'kind' => 'note',
            'body' => 'Offert skickades inte automatiskt: ' . $why . '. Ärendet lämnas till er.',
            'created_at' => $failAt,
        ]],
    ],
], [
    sol_inbound('m-varfor', 't-varfor', 'eget', '2026-10-15 00:30:00'),
]);
$whyRow = sol_row('offert-sol-jarfalla-eget');
sol_nt(str_contains((string) ($whyRow['problem'] ?? ''), 'senaste utskicket misslyckades'), 'felraden säger att utskicket misslyckades');
sol_nt(str_contains((string) ($whyRow['problem'] ?? ''), $why), 'felraden visar orsaken från anteckningen, fick ' . ($whyRow['problem'] ?? ''));
sol_nt(($whyRow['status'] ?? '') === 'Problem', 'ensam felanteckning är Problem');

$rows = jeab_notiser_catalog();
$filter = ['co' => '', 'ch' => '', 'st' => ''];
$visible = jeab_notiser_filter($rows, $filter);
$counts = jeab_notiser_counts($rows);
$html = require dirname(__DIR__) . '/src/pages/notiser.php';
sol_nt(str_contains($html, 'senaste utskicket misslyckades: ' . $why), 'sidan visar orsaken i notisraden');
sol_nt(str_contains($html, 'class="notis-problem"'), 'orsaken ligger i notisradens problemtext');
sol_nt(!str_contains($html, $fakeTo) && !str_contains($html, $graphSecret), 'sidan visar varken mottagare eller hemlighet');

echo "OK  solstatus\n";
