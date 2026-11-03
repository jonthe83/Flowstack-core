<?php

declare(strict_types=1);

/**
 * Syntetiska kontakter och mejl. Inga riktiga kunder.
 * kontakter.stats och kontakter.text_export läser bara.
 */

$tmp = sys_get_temp_dir() . '/jeab-kx-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
$_ENV['JEAB_DISABLE_SQLITE'] = '0';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'webhook-kx',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function kx(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @return array<string, string> */
function kx_tree(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $out[$file->getPathname()] = hash('sha256', (string) file_get_contents($file->getPathname()));
    }
    ksort($out);

    return $out;
}

/** SQLite rör -wal och -shm vid läsning. Det är inte en exportfil. */
function kx_app_files(array $tree): array
{
    $out = [];
    foreach ($tree as $path => $hash) {
        if (str_starts_with(basename((string) $path), 'app.sqlite')) {
            continue;
        }
        $out[$path] = $hash;
    }

    return $out;
}

function kx_check_digit(string $nine): string
{
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $n = (int) $nine[$i];
        if ($i % 2 === 0) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
    }

    return (string) ((10 - ($sum % 10)) % 10);
}

$nine = '800101123';
$ten = $nine . kx_check_digit($nine);
$twelve = '19' . $ten;
$dashed12 = substr($twelve, 0, 8) . '-' . substr($twelve, 8);
$dashed10 = substr($ten, 0, 6) . '-' . substr($ten, 6);
$badDash = '800101-0000';
kx(jeab_norm_pnr($twelve) === $twelve, 'syntetiskt personnummer klarar datum och Luhn');
kx(jeab_norm_pnr($badDash) === '', 'streckat nummer med fel kontrollsiffra är inget personnummer');
kx(jeab_kontakter_export_mask($dashed12) === '[PNR]', 'ÅÅÅÅMMDD-XXXX maskeras');
kx(jeab_kontakter_export_mask($dashed10) === '[PNR]', 'ÅÅMMDD-XXXX maskeras');
kx(jeab_kontakter_export_mask($twelve) === '[PNR]', '12 siffror som är personnummer maskeras');
kx(jeab_kontakter_export_mask($ten) === '[PNR]', '10 siffror som är personnummer maskeras');
kx(jeab_kontakter_export_mask($badDash) === '[PNR]', 'streckat datum maskeras även när Luhn faller');
kx(jeab_kontakter_export_mask('4111111111111111') === '[KONTO]', 'kortnummer maskeras');
kx(jeab_kontakter_export_mask('4111 1111 1111 1111') === '[KONTO]', 'kortnummer med mellanslag maskeras');
kx(jeab_kontakter_export_mask('8327-9123456') === '[KONTO]', 'clearing och kontonummer maskeras');
kx(jeab_kontakter_export_mask('SE4550000000058398257466') === '[KONTO]', 'IBAN maskeras');
kx(jeab_kontakter_export_mask('0701112233') === '0701112233', 'telefon som inte är personnummer lämnas');
kx(jeab_kontakter_export_mask('12345') === '12345', 'postnummer lämnas');
kx(jeab_kontakter_export_limit([]) === 100 && jeab_kontakter_export_limit(['limit' => 0]) === 100, 'limit saknas eller 0 blir 100');
kx(jeab_kontakter_export_limit(['limit' => 201]) === 200 && jeab_kontakter_export_limit(['limit' => 2]) === 2, 'limit tak är 200');
kx(jeab_kontakter_export_max_chars([]) === 4000 && jeab_kontakter_export_max_chars(['max_chars_per_message' => 40]) === 40, 'max_chars standard 4000');

$emptyBefore = kx_tree($tmp);
$emptyStats = jeab_kontakter_stats();
$emptyExport = jeab_kontakter_text_export(['missing_address' => 1, 'write' => 1, 'dry' => 0]);
kx(($emptyStats['total'] ?? -1) === 0 && ($emptyStats['with_full_address'] ?? -1) === 0 && ($emptyStats['none'] ?? -1) === 0, 'tom katalog ger nollor');
kx(($emptyExport['total'] ?? -1) === 0 && ($emptyExport['returned'] ?? -1) === 0 && array_key_exists('next_cursor', $emptyExport) && $emptyExport['next_cursor'] === null, 'tom export har ingen nästa cursor');
kx(!is_file($tmp . '/inkorg/messages.json') && !is_file($tmp . '/inkorg/threads.json') && !is_file($tmp . '/purchases.json') && !is_file($tmp . '/kontakter.json'), 'tom läsning skapar inga json-källor');

$src = (string) file_get_contents(dirname(__DIR__) . '/src/kontakter_export.php');
foreach (['file_put_contents', 'fwrite', 'ftruncate', 'jeab_kontakter_mutate', 'jeab_inkorg_locked', 'jeab_inkorg_thread_write', 'jeab_hs_import_save', 'mkdir', 'unlink', 'error_log', 'rename('] as $banned) {
    kx(!str_contains($src, $banned), 'exportkoden skriver inte via ' . $banned);
}
$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$kontakterSrc = (string) file_get_contents(dirname(__DIR__) . '/src/kontakter.php');
kx(str_contains($hookSrc, "'kontakter.stats'") && str_contains($hookSrc, "'kontakter.text_export'"), 'hook.php tar emot stats och text_export');
kx(str_contains($kontakterSrc, "require_once __DIR__ . '/kontakter_export.php';"), 'kontakter.php laddar exporten som dedupe');

$owner = static function (string $email, string $phone = ''): array {
    return [[
        'name' => 'Syntetisk Person',
        'personnummer' => '',
        'contact' => true,
        'email' => $email,
        'phone' => $phone,
    ]];
};
$contacts = [
    'kt_a' => [
        'id' => 'kt_a', 'street' => 'Exempelgatan 12', 'postal_code' => '12345', 'city' => 'Teststad',
        'owners' => $owner('a@example.test'), 'source' => 'hs-import',
        'created_at' => '2026-06-02 10:00:00', 'updated_at' => '2026-06-02 10:00:00',
    ],
    'kt_b' => [
        'id' => 'kt_b', 'street' => '', 'postal_code' => '', 'city' => '',
        'owners' => $owner('b@example.test'), 'source' => 'graph',
        'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00',
    ],
    'kt_c' => [
        'id' => 'kt_c', 'street' => '', 'postal_code' => '', 'city' => '',
        'owners' => $owner('', '0701112233'), 'source' => 'manual',
        'created_at' => '2026-06-02 10:00:00', 'updated_at' => '2026-06-02 10:00:00',
    ],
    'kt_d' => [
        'id' => 'kt_d', 'street' => '', 'postal_code' => '', 'city' => '',
        'owners' => $owner(''), 'source' => '',
        'created_at' => '2026-06-02 10:00:00', 'updated_at' => '2026-06-02 10:00:00',
    ],
    'kt_e' => [
        'id' => 'kt_e', 'street' => '', 'postal_code' => '', 'city' => '',
        'owners' => $owner('e@example.test'), 'source' => '',
        'created_at' => '2026-06-02 10:00:00', 'updated_at' => '2026-06-02 10:00:00',
    ],
    'kt_f' => [
        'id' => 'kt_f', 'street' => 'Exempelgatan', 'postal_code' => '', 'city' => 'Teststad',
        'owners' => $owner('f@example.test'), 'source' => 'inbox',
        'created_at' => '2026-06-02 10:00:00', 'updated_at' => '2026-06-02 10:00:00',
    ],
    'kt_g' => [
        'id' => 'kt_g', 'street' => 'Annangatan 2', 'postal_code' => '11122', 'city' => 'Teststad',
        'owners' => $owner('g@example.test'), 'source' => '',
        'created_at' => '2026-06-02 10:00:00', 'updated_at' => '2026-06-02 10:00:00',
    ],
    'kt_h' => [
        'id' => 'kt_h', 'street' => 'Huvudgatan 3', 'postal_code' => '11122', 'city' => 'Teststad',
        'owners' => $owner('h@example.test'), 'source' => '',
        'created_at' => '2026-06-02 10:00:00', 'updated_at' => '2026-06-02 10:00:00',
    ],
    'kt_i' => [
        'id' => 'kt_i', 'street' => 'Idgatan 4', 'postal_code' => '11122', 'city' => 'Teststad',
        'owners' => $owner('i@example.test'), 'source' => '',
        'created_at' => '2026-06-02 10:00:00', 'updated_at' => '2026-06-02 10:00:00',
    ],
    'kt_j' => [
        'id' => 'kt_j', 'street' => 'Jogatan 5', 'postal_code' => '11122', 'city' => 'Teststad',
        'owners' => $owner('j@example.test'), 'source' => '',
        'created_at' => '2026-06-02 10:00:00', 'updated_at' => '2026-06-02 10:00:00',
    ],
    'kt_k' => [
        'id' => 'kt_k', 'street' => 'Kvarngatan 6', 'postal_code' => '11122', 'city' => 'Teststad',
        'owners' => $owner('k@example.test'), 'source' => '',
        'created_at' => '2026-06-02 10:00:00', 'updated_at' => '2026-06-02 10:00:00',
    ],
];
file_put_contents($tmp . '/kontakter.json', json_encode(['contacts' => $contacts], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$seed = jeab_kontakter_pdo_store();
if ($seed instanceof JeabPdoContactStore) {
    $seed->replaceAll(jeab_kontakter_json_file_load());
}
unset($GLOBALS['_JEAB_KONTAKTER_MEM'], $GLOBALS['_JEAB_KONTAKTER_MEM_KEY']);
file_put_contents($tmp . '/store.json', json_encode([
    'reminders' => [[
        'id' => 1,
        'contact_id' => 'kt_e',
        'customer_name' => 'Syntetisk Person',
        'email' => 'e@example.test',
    ]],
    'bookings' => [],
], JSON_UNESCAPED_UNICODE));

$hsBody = "Personnummer {$dashed12} och {$dashed10} och {$twelve} och {$ten} samt ogiltigt {$badDash}.\n"
    . "Kort 4111111111111111 clearing 8327-9123456 iban SE4550000000058398257466.\n"
    . str_repeat('M', 80) . "\n"
    . "Namn: Ada Exempel\n"
    . "Meddelande: Hej syntetiskt\n"
    . "{#HS:abc-123} https://secure.helpscout.net/conversation/9 [Bilaga: offert.pdf]\n"
    . "Med vänlig hälsning\n"
    . "Ada Exempel\n"
    . "Exempelgatan 12\n"
    . "123 45 Teststad\n"
    . "070-111 22 33\n"
    . $dashed12 . "\n"
    . "On Monday wrote:\n"
    . "> gammal text\n";
$hsA = 'hsi-aaaaaaaaaaaaaaaa';
$hsG = 'hsi-bbbbbbbbbbbbbbbb';
$conv = static function (string $id, string $contact, array $messages): array {
    return [
        'id' => $id,
        'company' => 'jarfalla',
        'contact_id' => $contact,
        'import_source' => 'helpscout_import',
        'messages' => $messages,
        'meta' => ['contact_id' => $contact, 'import_source' => 'helpscout_import', 'status' => 'closed'],
    ];
};
$pathA = jeab_hs_import_conv_path($hsA);
$pathG = jeab_hs_import_conv_path($hsG);
kx(is_string($pathA) && is_string($pathG), 'sökväg för syntetiskt Help Scout-ärende');
if (!is_dir(dirname((string) $pathA))) {
    mkdir(dirname((string) $pathA), 0775, true);
}
if (!is_dir(dirname((string) $pathG))) {
    mkdir(dirname((string) $pathG), 0775, true);
}
file_put_contents((string) $pathA, json_encode($conv($hsA, 'kt_a', [
    [
        'id' => 'msg-old',
        'from' => 'a@example.test',
        'subject' => 'OLD_MARKOR',
        'body' => 'Gammalt syntetiskt mejl.',
        'received_at' => '2020-05-01T09:00:00+02:00',
        'source' => 'helpscout_import',
        'hs_thread_type' => 'customer',
    ],
    [
        'id' => 'msg-new',
        'from' => 'a@example.test',
        'subject' => 'Hej syntetiskt',
        'body' => $hsBody,
        'received_at' => '2026-03-01T09:00:00+01:00',
        'source' => 'helpscout_import',
        'hs_thread_type' => 'customer',
        'attachments' => [['filename' => 'offert.pdf', 'data' => 'inte-med']],
    ],
    [
        'id' => 'msg-out',
        'from' => 'saljare4@jarfalla.example.test',
        'subject' => 'Svar',
        'body' => 'UTGAENDE_MARKOR',
        'received_at' => '2026-03-01T10:00:00+01:00',
        'source' => 'helpscout_import',
        'hs_thread_type' => 'message',
    ],
    [
        'id' => 'msg-note',
        'from' => 'saljare4@jarfalla.example.test',
        'subject' => 'Anteckning',
        'body' => 'NOTE_MARKOR',
        'received_at' => '2026-03-01T11:00:00+01:00',
        'source' => 'helpscout_import',
        'internal_note' => true,
        'hs_thread_type' => 'note',
    ],
]), JSON_UNESCAPED_UNICODE));
file_put_contents((string) $pathG, json_encode($conv($hsG, 'kt_g', [[
    'id' => 'msg-g',
    'from' => 'g@example.test',
    'subject' => 'Kort hej',
    'body' => 'Bara en syntetisk rad.',
    'received_at' => '2026-04-02T09:00:00+02:00',
    'source' => 'helpscout_import',
    'hs_thread_type' => 'customer',
]]), JSON_UNESCAPED_UNICODE));
$indexPath = jeab_hs_import_root() . '/index.jsonl';
file_put_contents($indexPath, json_encode([
    'id' => $hsA, 'contact_id' => 'kt_a', 'from' => 'a@example.test', 'subject' => 'Hej syntetiskt', 'deleted' => false,
]) . "\n" . json_encode([
    'id' => $hsG, 'contact_id' => 'kt_g', 'from' => 'g@example.test', 'subject' => 'Kort hej', 'deleted' => false,
]) . "\n");
unset($GLOBALS['_JEAB_HS_IMPORT_INDEX']);

$inboxMessages = [
    ['id' => 'm-b', 'thread_id' => 'th-b', 'from' => 'b@example.test', 'subject' => 'Inbox B', 'body' => 'Hej från B.', 'received_at' => '2026-04-01T08:00:00+02:00', 'source' => 'bridge'],
    ['id' => 'm-f', 'thread_id' => 'th-f', 'from' => 'nagon@example.test', 'subject' => 'Inbox F', 'body' => 'Kopplad via kontakt.', 'received_at' => '2026-04-01T08:05:00+02:00', 'source' => 'bridge'],
    ['id' => 'm-h', 'thread_id' => 'th-h', 'from' => 'info@wix-forms.com', 'subject' => 'Formulär', 'body' => "Namn: Hedda Exempel\nE-post: h@example.test\nMeddelande: Vill ha info\n", 'received_at' => '2026-04-01T08:10:00+02:00', 'source' => 'bridge'],
    ['id' => 'm-i', 'thread_id' => 'th-i', 'from' => 'leads@hitta-solceller.se', 'subject' => 'Hitta', 'body' => "Namn: Ivar Exempel\nE-post: i@example.test\n", 'received_at' => '2026-04-01T08:15:00+02:00', 'source' => 'bridge'],
    ['id' => 'm-j', 'thread_id' => 'th-j', 'from' => 'noreply@kalkyl.jarfalla.example.test', 'subject' => 'Kalkyl', 'body' => "Namn: Julia Exempel\nE-post: j@example.test\n", 'received_at' => '2026-04-01T08:20:00+02:00', 'source' => 'bridge'],
    ['id' => 'm-k', 'thread_id' => 'th-k', 'from' => 'k@example.test', 'subject' => 'Offertförfrågan', 'body' => 'Vi vill ha en offert på ett syntetiskt tak.', 'received_at' => '2026-04-01T08:25:00+02:00', 'source' => 'bridge', 'kind' => 'offert'],
    ['id' => 'm-k-out', 'thread_id' => 'th-k', 'from' => 'k@example.test', 'subject' => 'Skickad offert', 'body' => 'OUTBOUND_MARKOR', 'received_at' => '2026-04-01T09:00:00+02:00', 'source' => 'outbound-quote', 'kind' => 'offert'],
];
$inboxThreads = [
    'th-f' => ['contact_id' => 'kt_f', 'status' => 'open'],
    'th-h' => ['contact_id' => 'kt_h', 'status' => 'open'],
    'th-i' => ['contact_id' => 'kt_i', 'status' => 'open'],
    'th-j' => ['contact_id' => 'kt_j', 'status' => 'open'],
    'th-k' => ['contact_id' => 'kt_k', 'status' => 'open'],
];
$kxPdo = jeab_store()->pdo();
kx($kxPdo instanceof PDO && jeab_settings_put($kxPdo, 'sqlite', 'inkorg_storage', 'sql'), 'inkorgen ställs på SQL');
kx(jeab_inkorg_storage_mode() === 'sql', 'decoy-filen är inte källan');
foreach (jeab_hs_archive_index_json_map() as $hsRow) {
    $hsId = (string) ($hsRow['id'] ?? '');
    kx(jeab_hs_import_store_index_put($hsId, $hsRow), 'arkivindexet ligger i SQL');
}
foreach ([$hsA, $hsG] as $hsId) {
    $hsConv = json_decode((string) file_get_contents((string) jeab_hs_import_conv_path($hsId)), true);
    kx(is_array($hsConv) && jeab_hs_import_store_conv_put($hsId, $hsConv), 'arkivärendet ligger i SQL');
}
unset($GLOBALS['_JEAB_HS_IMPORT_INDEX']);
jeab_inkorg_locked('messages', static function (?array $doc) use ($inboxMessages): array {
    unset($doc);

    return [null, ['messages' => $inboxMessages]];
});
foreach ($inboxThreads as $threadId => $meta) {
    jeab_inkorg_thread_write($threadId, static function (array $row) use ($meta): array {
        return array_merge($row, $meta);
    });
}
unset($GLOBALS['_JEAB_INKORG_MSGS'], $GLOBALS['_JEAB_INKORG_MSGS_FP'], $GLOBALS['_JEAB_INKORG_META'], $GLOBALS['_JEAB_INKORG_META_FP']);
if (!is_dir($tmp . '/inkorg')) {
    mkdir($tmp . '/inkorg', 0775, true);
}
file_put_contents($tmp . '/inkorg/messages.json', json_encode(['messages' => [
    ['id' => 'decoy', 'thread_id' => 'th-decoy', 'from' => 'd@example.test', 'subject' => 'DECOY', 'body' => 'Inte SQL.', 'received_at' => '2026-04-01T08:00:00+02:00', 'source' => 'bridge'],
]], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/inkorg/threads.json', json_encode(['threads' => [
    'th-decoy' => ['contact_id' => 'kt_d', 'status' => 'open'],
]], JSON_UNESCAPED_UNICODE));

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-kx-test']));
$runner = $tmp . '/hook-runner.php';
file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$GLOBALS['_JEAB_DATA_DIR'] = $argv[1];
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $argv[1];
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
$_ENV['JEAB_DISABLE_SQLITE'] = '0';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $argv[2];
$_SERVER['CONTENT_TYPE'] = 'application/json';
$GLOBALS['_JEAB_RAW_BODY'] = $argv[3];
require $argv[4];
PHP);

$before = kx_tree($tmp);
$stats = jeab_kontakter_stats();
kx(($stats['ok'] ?? false) === true, 'stats svarar ok');
kx(($stats['total'] ?? -1) === 11, 'stats total är 11');
kx(($stats['with_full_address'] ?? -1) === 6, 'sex kontakter har gata, nummer och ort');
kx(($stats['only_email_or_phone'] ?? -1) === 4, 'fyra saknar hel adress men har e-post eller telefon');
kx(($stats['none'] ?? -1) === 1, 'en kontakt saknar adress, e-post och telefon');
kx(($stats['with_full_address'] + $stats['only_email_or_phone'] + $stats['none']) === $stats['total'], 'adressklasserna summerar till total');
$by = (array) ($stats['by_source'] ?? []);
kx(($by['hs_import'] ?? -1) === 2 && ($by['inbox'] ?? -1) === 6 && ($by['link'] ?? -1) === 1 && ($by['manual'] ?? -1) === 1, 'källa räknas när den går att se');
kx(array_sum($by) === 10, 'okänd källa räknas inte in i by_source');
$statsJson = json_encode($stats, JSON_UNESCAPED_UNICODE);
kx(is_string($statsJson) && !str_contains($statsJson, '@') && !str_contains($statsJson, 'Exempelgatan') && !str_contains($statsJson, 'kt_'), 'stats innehåller ingen persondata');

$byId = static function (array $export): array {
    $out = [];
    foreach ((array) ($export['contacts'] ?? []) as $row) {
        if (is_array($row)) {
            $out[(string) ($row['contact_id'] ?? '')] = $row;
        }
    }

    return $out;
};

$all = jeab_kontakter_text_export(['limit' => 200, 'dry' => 0, 'write' => 1]);
kx(($all['ok'] ?? false) === true && ($all['total'] ?? -1) === 11 && ($all['returned'] ?? -1) === 11 && $all['next_cursor'] === null, 'export utan filter ger alla kontakter');
$allRows = $byId($all);
kx(isset($allRows['kt_a']['messages']) && count($allRows['kt_a']['messages']) === 2, 'Help Scout ger två inkommande mejl');
$newMsg = null;
foreach ($allRows['kt_a']['messages'] as $message) {
    if ((string) ($message['subject'] ?? '') === 'Hej syntetiskt') {
        $newMsg = $message;
    }
    kx(($message['direction'] ?? '') === 'incoming' && ($message['source'] ?? '') === 'hs', 'Help Scout-mejl är inkommande hs');
    kx(!array_key_exists('attachments', $message), 'svaret har inga bilagor');
}
kx(is_array($newMsg), 'det nya Help Scout-mejlet finns');
$newBody = (string) ($newMsg['body'] ?? '');
$newJson = json_encode($newMsg, JSON_UNESCAPED_UNICODE);
kx(str_contains($newBody, '[PNR]') && str_contains($newBody, '[KONTO]'), 'brödtexten maskerar personnummer och konto');
kx(is_string($newJson) && !str_contains($newJson, $twelve) && !str_contains($newJson, $ten) && !str_contains($newJson, '800101-0000') && !str_contains($newJson, '4111111111111111') && !str_contains($newJson, '8327-9123456') && !str_contains($newJson, 'SE4550000000058398257466'), 'råa nummer finns inte i mejlet');
kx(is_string($newJson) && !str_contains($newJson, '{#HS:') && !str_contains(strtolower($newJson), 'helpscout') && !str_contains($newJson, 'offert.pdf') && !str_contains($newJson, 'gammal text') && !str_contains($newJson, 'UTGAENDE_MARKOR') && !str_contains($newJson, 'NOTE_MARKOR'), 'citat, HS-kod, bilaga och utgående text är borta');
$signature = (string) ($newMsg['signature'] ?? '');
kx(str_contains($signature, 'Med vänlig hälsning') && str_contains($signature, 'Exempelgatan 12') && str_contains($signature, 'Teststad') && str_contains($signature, '070-111 22 33'), 'signaturen behåller adress, ort och telefon');
kx(str_contains($signature, '[PNR]') && !str_contains($signature, $dashed12) && !str_contains($signature, $twelve), 'personnummer i signaturen maskeras');
kx(!str_contains($newBody, 'Med vänlig hälsning') && !str_contains($newBody, '070-111 22 33') && !str_contains($newBody, 'gammal text'), 'brödtexten har varken signatur eller citat');
$fields = (array) ($newMsg['form_fields'] ?? []);
kx(($fields['Namn'] ?? '') === 'Ada Exempel' && ($fields['Meddelande'] ?? '') === 'Hej syntetiskt', 'formulärfält blir nyckel och värde');
kx(!array_key_exists('fields', $newMsg), 'formulärfälten heter form_fields');
kx(!str_contains($newBody, 'Namn:') && !str_contains($newBody, 'Meddelande:'), 'formulärfälten ligger inte kvar i brödtexten');
kx(($allRows['kt_a']['street'] ?? '') === 'Exempelgatan 12' && ($allRows['kt_a']['postal'] ?? '') === '12345' && ($allRows['kt_a']['city'] ?? '') === 'Teststad', 'adressfälten följer med');
kx(($allRows['kt_b']['email'] ?? '') === 'b@example.test' && count($allRows['kt_b']['messages']) === 1 && ($allRows['kt_b']['messages'][0]['source'] ?? '') === 'inbox', 'e-post utan contact_id kopplar inkorgsmejlet');
kx(($allRows['kt_f']['messages'][0]['thread_id'] ?? '') === 'th-f' && ($allRows['kt_f']['messages'][0]['source'] ?? '') === 'inbox', 'contact_id på tråden kopplar mejlet');
kx(($allRows['kt_f']['street'] ?? '') === 'Exempelgatan' && ($allRows['kt_f']['city'] ?? '') === 'Teststad' && ($allRows['kt_c']['phone'] ?? '') === '0701112233', 'ofullständig adress och telefon lämnas som de är');
kx(($allRows['kt_h']['messages'][0]['source'] ?? '') === 'wix' && ($allRows['kt_i']['messages'][0]['source'] ?? '') === 'hitta' && ($allRows['kt_j']['messages'][0]['source'] ?? '') === 'kalkyl', 'formulär får källa wix, hitta och kalkyl');
kx((array) ($allRows['kt_h']['messages'][0]['form_fields'] ?? []) !== [] && (($allRows['kt_h']['messages'][0]['form_fields']['Meddelande'] ?? '') === 'Vill ha info'), 'wix-fält följer med');
kx(count($allRows['kt_k']['messages']) === 1 && ($allRows['kt_k']['messages'][0]['source'] ?? '') === 'quote' && ($allRows['kt_k']['messages'][0]['direction'] ?? '') === 'incoming', 'bara den inkommande offerten följer med');
$whole = json_encode($all, JSON_UNESCAPED_UNICODE);
kx(is_string($whole) && !str_contains($whole, 'UTGAENDE_MARKOR') && !str_contains($whole, 'OUTBOUND_MARKOR') && !str_contains($whole, 'NOTE_MARKOR') && !str_contains($whole, 'inte-med'), 'utgående, anteckning och bilageinnehåll saknas i hela svaret');

$missingIds = [];
$cursor = '';
$guard = 0;
do {
    $page = jeab_kontakter_text_export(['missing_address' => 1, 'limit' => 2, 'cursor' => $cursor]);
    kx(($page['total'] ?? -1) === 5 && ($page['returned'] ?? -1) === count($page['contacts'] ?? []), 'saknad adress har stabil total');
    foreach ((array) ($page['contacts'] ?? []) as $row) {
        $missingIds[] = (string) ($row['contact_id'] ?? '');
    }
    $next = $page['next_cursor'] ?? null;
    kx($next === null || $next === (string) end($missingIds), 'next_cursor är sista id på sidan');
    $cursor = is_string($next) ? $next : '';
    $guard++;
} while ($cursor !== '' && $guard < 6);
kx($missingIds === ['kt_b', 'kt_c', 'kt_d', 'kt_e', 'kt_f'], 'paging följer contact_id och hoppar över hel adress');
$offsetPage = jeab_kontakter_text_export(['missing_address' => 1, 'limit' => 1, 'offset' => 1]);
kx(($offsetPage['returned'] ?? -1) === 1 && (($offsetPage['contacts'][0]['contact_id'] ?? '') === 'kt_c'), 'offset hoppar första kontakten');

$since = jeab_kontakter_text_export(['since' => '2026-01-01', 'limit' => 200]);
$sinceIds = array_keys($byId($since));
kx(!in_array('kt_b', $sinceIds, true) && in_array('kt_a', $sinceIds, true), 'since följer kontaktens updated_at');
$sinceA = $byId($since)['kt_a']['messages'] ?? [];
$subjects = array_map(static fn(array $m): string => (string) ($m['subject'] ?? ''), $sinceA);
kx(!in_array('OLD_MARKOR', $subjects, true) && in_array('Hej syntetiskt', $subjects, true), 'since tar bort äldre mejl');

$forms = $byId(jeab_kontakter_text_export(['sources' => 'forms', 'limit' => 50]));
kx(array_keys($forms) === ['kt_h', 'kt_i', 'kt_j'], 'forms ger wix, hitta och kalkyl');
$quotes = $byId(jeab_kontakter_text_export(['sources' => ['quotes']]));
kx(array_keys($quotes) === ['kt_k'], 'quotes ger offertmejlet');
$hsOnly = array_keys($byId(jeab_kontakter_text_export(['sources' => 'hs'])));
kx($hsOnly === ['kt_a', 'kt_g'], 'hs ger importen');
$inboxOnly = array_keys($byId(jeab_kontakter_text_export(['sources' => 'inbox'])));
kx($inboxOnly === ['kt_b', 'kt_f'], 'inbox ger bara vanliga mejl');

$short = $byId(jeab_kontakter_text_export(['sources' => 'hs', 'max_chars_per_message' => 40]));
$shortBody = '';
foreach ((array) ($short['kt_a']['messages'] ?? []) as $message) {
    if ((string) ($message['subject'] ?? '') === 'Hej syntetiskt') {
        $shortBody = (string) ($message['body'] ?? '');
    }
}
kx(mb_strlen($shortBody) <= 40 && str_contains($shortBody, '[PNR]') && !str_contains($shortBody, $twelve), 'max_chars klipper efter maskering');

kx(kx_app_files(kx_tree($tmp)) === kx_app_files($before), 'stats och export skriver ingenting');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$denied = jeab_kontakter_export_dispatch(['action' => 'kontakter.stats']);
kx(($denied['status'] ?? 0) === 401 && ($denied['ok'] ?? true) === false, 'utan token blir det 401');
kx(kx_app_files(kx_tree($tmp)) === kx_app_files($before), 'nekad hook skriver inget');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-kx-test';
$allowed = jeab_kontakter_export_dispatch(['action' => 'kontakter.text_export', 'missing_address' => 1, 'limit' => 1, 'dry' => 0]);
kx(($allowed['status'] ?? 0) === 200 && ($allowed['returned'] ?? -1) === 1 && ($allowed['total'] ?? -1) === 5, 'dispatch med token är läsning');
kx(kx_app_files(kx_tree($tmp)) === kx_app_files($before), 'dispatch skriver inget');

$runHook = static function (string $token, string $body) use ($tmp, $runner): array {
    $out = [];
    $code = 0;
    exec(
        'php ' . escapeshellarg($runner) . ' '
        . escapeshellarg($tmp) . ' '
        . escapeshellarg($token) . ' '
        . escapeshellarg($body) . ' '
        . escapeshellarg(dirname(__DIR__) . '/hook.php'),
        $out,
        $code
    );

    return ['code' => $code, 'json' => json_decode(implode("\n", $out), true), 'raw' => implode("\n", $out)];
};
$hookDeny = $runHook('', json_encode(['action' => 'kontakter.stats']));
kx($hookDeny['code'] === 0 && is_array($hookDeny['json']) && ($hookDeny['json']['ok'] ?? true) === false, 'hook.php utan token nekar stats');
kx(!str_contains($hookDeny['raw'], '@'), 'nekat hook-svar har ingen e-post');
$hookStats = $runHook('tok-kx-test', json_encode(['action' => 'kontakter.stats', 'dry' => 0]));
kx($hookStats['code'] === 0 && is_array($hookStats['json']) && ($hookStats['json']['total'] ?? -1) === 11 && !str_contains($hookStats['raw'], '@'), 'hook.php kontakter.stats');
$hookExport = $runHook('tok-kx-test', json_encode(['action' => 'kontakter.text_export', 'sources' => 'quotes', 'limit' => 10]));
kx($hookExport['code'] === 0 && is_array($hookExport['json']) && ($hookExport['json']['returned'] ?? -1) === 1 && !str_contains($hookExport['raw'], 'OUTBOUND_MARKOR'), 'hook.php kontakter.text_export');
$after = kx_tree($tmp);
$new = array_values(array_diff(array_keys($after), array_keys($before)));
$allowedNew = [$tmp . '/store.json'];
kx($new === [] || $new === $allowedNew, 'hook.php skapar inte exportfiler: ' . implode(', ', $new));
foreach ($before as $path => $hash) {
    $base = basename($path);
    if (str_starts_with($base, 'app.sqlite')) {
        continue;
    }
    kx(($after[$path] ?? '') === $hash, 'oförändrad efter hook: ' . $base);
}

echo "kontakter_export_test klar\n";
