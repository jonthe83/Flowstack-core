<?php

declare(strict_types=1);

/**
 * Help Scout-import med fejkade HTTP-svar. Ingen riktig nyckel, ingen skrivning till Help Scout.
 */

$tmp = sys_get_temp_dir() . '/jeab-hsi-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
file_put_contents($tmp . '/helpscout.json', json_encode([
    'client_id' => 'hs-test-client',
    'client_secret' => 'hs-test-secret',
    'mailbox_id' => 111,
    'mailbox_id_malmo' => 222,
    'import_per_minute' => 200,
    'import_budget_seconds' => 20,
], JSON_UNESCAPED_UNICODE));
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/layout.php';
jeab_ordrar_external_live(false);

function hsi(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function hsi_json(int $code, array $body, array $headers = []): array
{
    return ['code' => $code, 'headers' => $headers, 'body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
}

function hsi_conv(int $id, string $status, int $customerId, string $email, string $first, string $last, string $subject): array
{
    return [
        'id' => $id,
        'status' => $status,
        'subject' => $subject,
        'primaryCustomer' => ['id' => $customerId, 'email' => $email, 'first' => $first, 'last' => $last],
    ];
}

/** @return array<string, mixed> */
function hsi_customer(int $id, string $email, string $first, string $last, string $street, string $postal, string $city, string $phone): array
{
    return [
        'id' => $id,
        'firstName' => $first,
        'lastName' => $last,
        'email' => $email,
        '_embedded' => [
            'emails' => [['value' => $email]],
            'phones' => $phone !== '' ? [['value' => $phone]] : [],
            'address' => ['lines' => [$street], 'city' => $city, 'postalCode' => $postal],
        ],
    ];
}

$annaHtml = '<p>Hej, jag vill ha batteri på taket.</p><p>&gt; Tidigare meddelande som inte ska synas</p><p>Skickat från min iPhone</p>';
$outHtml = '<p>Hej Anna, vi återkommer.</p><p>&gt; Citerad utgående historik</p><p>Skickat från min iPhone</p>';
$threads = [
    1001 => [
        ['id' => 501, 'type' => 'customer', 'createdAt' => '2024-01-01T10:00:00Z', 'body' => $annaHtml,
            'createdBy' => ['first' => 'Anna', 'last' => 'Alm', 'email' => 'anna@post.se'],
            'customer' => ['id' => 50, 'email' => 'anna@post.se', 'first' => 'Anna', 'last' => 'Alm'],
            'source' => ['messageId' => 'anna-msg@post.se'],
            '_embedded' => ['attachments' => [[
                'id' => 77, 'filename' => 'tak.jpg', 'mimeType' => 'image/jpeg', 'size' => 12,
                'data' => base64_encode('NOT-STORED-TAK'),
            ]]]],
        ['id' => 502, 'type' => 'reply', 'createdAt' => '2024-01-02T10:00:00Z', 'body' => $outHtml,
            'createdBy' => ['first' => 'Elin', 'last' => 'Energi', 'email' => 'saljare4@jarfalla.example.test'],
            '_embedded' => ['attachments' => [[
                'id' => 88, 'filename' => 'offert.pdf', 'mimeType' => 'application/pdf', 'size' => 9,
                'data' => base64_encode('OUTGOING-NOT-IMPORTED'),
            ]]]],
        ['id' => 503, 'type' => 'note', 'createdAt' => '2024-01-03T10:00:00Z', 'body' => '<p>Intern koll av taket</p>',
            'createdBy' => ['first' => 'Elin', 'last' => 'Energi', 'email' => 'saljare4@jarfalla.example.test']],
        ['id' => 504, 'type' => 'lineitem', 'createdAt' => '2024-01-04T10:00:00Z', 'body' => 'statusrad'],
    ],
    1002 => [
        ['id' => 601, 'type' => 'customer', 'createdAt' => '2024-02-01T10:00:00Z', 'body' => '<p>Stängt ärende om laddbox.</p>',
            'createdBy' => ['first' => 'Bo', 'last' => 'Berg', 'email' => 'bo@post.se'],
            'customer' => ['id' => 51, 'email' => 'bo@post.se', 'first' => 'Bo', 'last' => 'Berg']],
    ],
    1003 => [
        ['id' => 701, 'type' => 'customer', 'createdAt' => '2024-03-01T10:00:00Z', 'body' => '<p>Internt mejl som inte blir en kontakt.</p>',
            'createdBy' => ['email' => 'kontakt@jarfalla.example.test', 'first' => 'Kontakt', 'last' => 'JEAB'],
            'customer' => ['id' => 52, 'email' => 'kontakt@jarfalla.example.test', 'first' => 'Kontakt', 'last' => 'JEAB']],
    ],
    2001 => [
        ['id' => 801, 'type' => 'customer', 'createdAt' => '2024-04-01T10:00:00Z', 'body' => '<p>Pump i Malmö.</p>',
            'createdBy' => ['first' => 'Mia', 'last' => 'Malm', 'email' => 'mia@post.se'],
            'customer' => ['id' => 60, 'email' => 'mia@post.se', 'first' => 'Mia', 'last' => 'Malm']],
    ],
    1004 => [
        ['id' => 901, 'type' => 'customer', 'createdAt' => '2024-06-01T10:00:00Z', 'body' => '<p>Nytt mejl efter importen.</p>',
            'createdBy' => ['first' => 'Nils', 'last' => 'Ny', 'email' => 'nils@post.se'],
            'customer' => ['id' => 53, 'email' => 'nils@post.se', 'first' => 'Nils', 'last' => 'Ny']],
    ],
];
$customers = [
    50 => hsi_customer(50, 'anna@post.se', 'Anna', 'Alm', 'Storgatan 12', '177 32', 'Järfälla', '0701112233'),
    51 => hsi_customer(51, 'bo@post.se', 'Bo', 'Berg', 'Testgatan 9', '177 31', 'Järfälla', '0702223344'),
    52 => hsi_customer(52, 'kontakt@jarfalla.example.test', 'Kontakt', 'JEAB', '', '', '', ''),
    60 => hsi_customer(60, 'mia@post.se', 'Mia', 'Malm', 'Malmövägen 4', '211 20', 'Malmö', '0703334455'),
    53 => hsi_customer(53, 'nils@post.se', 'Nils', 'Ny', 'Nygatan 1', '177 30', 'Järfälla', '0704445566'),
];
$lists = [
    '111:1' => [hsi_conv(1001, 'active', 50, 'anna@post.se', 'Anna', 'Alm', 'Batteri på taket')],
    '111:2' => [
        hsi_conv(1002, 'closed', 51, 'bo@post.se', 'Bo', 'Berg', 'Stängd laddbox'),
        hsi_conv(1003, 'active', 52, 'kontakt@jarfalla.example.test', 'Kontakt', 'JEAB', 'Egen adress'),
    ],
    '222:1' => [hsi_conv(2001, 'pending', 60, 'mia@post.se', 'Mia', 'Malm', 'Malmö pump')],
];
$state = ['mode' => 'import', 'lists' => 0, 'data' => 0, 'always_429' => false];
$sleeps = [];
$GLOBALS['_JEAB_HS_IMPORT_SLEEP'] = static function (float $seconds) use (&$sleeps): void {
    $sleeps[] = $seconds;
};
$GLOBALS['_JEAB_HS_IMPORT_HTTP'] = static function (string $method, string $url) use (&$state, $lists, $threads, $customers): array {
    if ($method !== 'GET') {
        return hsi_json(405, ['error' => 'write']);
    }
    $parts = parse_url($url);
    $path = (string) ($parts['path'] ?? '');
    $q = [];
    parse_str((string) ($parts['query'] ?? ''), $q);
    if (str_contains($path, '/attachments/') && str_ends_with($path, '/data')) {
        $state['data']++;
        return ['code' => 200, 'headers' => [], 'body' => json_encode(['data' => base64_encode('HSBYTES'), 'state' => 'ok'])];
    }
    if (preg_match('#/v2/conversations/(\d+)/threads$#', $path, $m) === 1) {
        $id = (int) $m[1];
        $page = (int) ($q['page'] ?? 1);
        $rows = $page === 1 ? ($threads[$id] ?? []) : [];
        return hsi_json(200, ['_embedded' => ['threads' => $rows], 'page' => ['totalPages' => 1]]);
    }
    if (preg_match('#/v2/customers/(\d+)$#', $path, $m) === 1) {
        $row = $customers[(int) $m[1]] ?? null;
        return $row === null ? hsi_json(404, []) : hsi_json(200, $row);
    }
    if ($path === '/v2/conversations') {
        $state['lists']++;
        if ($state['always_429'] || $state['lists'] === 1) {
            return ['code' => 429, 'headers' => ['Retry-After: 2'], 'body' => ''];
        }
        $box = (string) ($q['mailbox'] ?? '');
        $page = (int) ($q['page'] ?? 1);
        $incremental = str_contains(rawurldecode($url), 'modifiedAt');
        if ($state['mode'] === 'incremental') {
            $rows = ($incremental && $box === '111') ? [hsi_conv(1004, 'active', 53, 'nils@post.se', 'Nils', 'Ny', 'Nytt efter import')] : [];
            return hsi_json(200, ['_embedded' => ['conversations' => $page === 1 ? $rows : []], 'page' => ['totalPages' => 1]]);
        }
        if (!$incremental) {
            $key = $box . ':' . $page;
            if (isset($lists[$key])) {
                $total = $box === '111' ? 2 : 1;
                return hsi_json(200, ['_embedded' => ['conversations' => $lists[$key]], 'page' => ['totalPages' => $total]]);
            }
        }
        return hsi_json(200, ['_embedded' => ['conversations' => []], 'page' => ['totalPages' => 1]]);
    }
    return hsi_json(404, []);
};

$src = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_helpscout_import.php');
hsi(!str_contains($src, 'CURLOPT_POST') && !str_contains($src, 'CURLOPT_PUT') && !str_contains($src, 'CURLOPT_CUSTOMREQUEST'), 'importmodulen har bara GET');
$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
hsi(str_contains($hookSrc, "str_starts_with(\$jeabHookAction, 'helpscout.')") && str_contains($hookSrc, 'jeab_hs_import_hook'), 'hook.php tar emot helpscout-anrop');
hsi(str_contains($src, 'helpscout.import') && str_contains($src, 'helpscout.import_status'), 'import och status är hook-aktioner');
hsi(jeab_hs_import_dry([]) && !jeab_hs_import_dry(['dry' => 0]) && !jeab_hs_import_dry(['dry' => '0']) && jeab_hs_import_dry(['dry' => 1]), 'dry är på om den utelämnas');
hsi(jeab_hs_import_rpm() === 200, 'taket är 200 anrop per minut');
file_put_contents($tmp . '/helpscout.json', json_encode([
    'client_id' => 'hs-test-client', 'client_secret' => 'hs-test-secret', 'mailbox_id' => 111, 'mailbox_id_malmo' => 222, 'import_per_minute' => 900,
]));
hsi(jeab_hs_import_rpm() === 200, 'högre takt än 200 kläms ner');
file_put_contents($tmp . '/helpscout.json', json_encode([
    'client_id' => 'hs-test-client', 'client_secret' => 'hs-test-secret', 'mailbox_id' => 111, 'mailbox_id_malmo' => 222, 'import_per_minute' => 200, 'import_budget_seconds' => 20,
]));
hsi(jeab_hs_import_budget([]) === 20 && jeab_hs_import_budget(['budget' => 1]) === 1, 'tidsbudgeten är 20 sekunder och går att sänka');
$lim = jeab_hs_import_disk_limit([]);
hsi($lim['min_bytes'] === 524288000 && abs($lim['min_ratio'] - 0.10) < 0.0001, 'diskgränsen är 500 MB eller 10 procent');
hsi(jeab_hs_import_disk_limit(['disk_min_bytes' => 0, 'disk_min_ratio' => 0]) === ['min_bytes' => 0, 'min_ratio' => 0.0], 'noll stänger av diskgränsen');
$threw = false;
try {
    jeab_hs_import_http('POST', 'https://api.helpscout.net/v2/conversations');
} catch (RuntimeException $e) {
    $threw = $e->getMessage() === 'hs_skriv';
}
hsi($threw, 'skrivmetod mot Help Scout stoppas');

$anna = jeab_kontakt_find_or_create([
    'name' => 'Anna Alm',
    'email' => 'anna@post.se',
    'street' => 'Storgatan 12',
    'postal_code' => '17732',
    'city' => 'Sparadort',
    'address' => 'Storgatan 12',
], false);
hsi(is_array($anna) && ($anna['id'] ?? '') !== '', 'Anna finns redan i kontaktregistret');
$annaId = (string) $anna['id'];
$beforeContacts = count(jeab_kontakter_load());

$state['always_429'] = true;
$state['lists'] = 0;
$sleeps = [];
$GLOBALS['_JEAB_HS_IMPORT_NEXT_AT'] = 0;
$boom = jeab_hs_import_run(['mailbox' => 'jarfalla', 'limit' => 5, 'dry' => 0, 'budget' => 30, 'disk_min_bytes' => 0, 'disk_min_ratio' => 0]);
hsi(in_array('jarfalla: hs_http_429', (array) $boom['errors'], true), '429 efter Retry-After blir ett fel');
hsi(max($sleeps ?: [0]) >= 2.0, 'Retry-After väntas ut');
hsi(jeab_inkorg_messages() === [], '429 skriver inga mejl');
$cp = jeab_hs_import_checkpoint();
hsi((int) ($cp['mailboxes']['jarfalla']['page'] ?? 0) === 1 && empty($cp['mailboxes']['jarfalla']['backfill_done']), 'checkpointen står kvar på sida 1');
@unlink(jeab_inkorg_dir() . '/helpscout-import.json');

$state['always_429'] = false;
$state['lists'] = 0;
$state['mode'] = 'import';
$sleeps = [];
$GLOBALS['_JEAB_HS_IMPORT_NEXT_AT'] = 0;
$dry = jeab_hs_import_run(['mailbox' => 'all', 'limit' => 10, 'budget' => 30]);
hsi(!empty($dry['dry']) && ($dry['contacts_merged'] ?? 0) === 1 && ($dry['contacts_created'] ?? 0) === 2 && ($dry['contacts_skipped'] ?? 0) === 1, 'torrkörning räknar slå ihop, skapa och hoppa över');
hsi(($dry['conversations'] ?? 0) === 4 && ($dry['messages'] ?? 0) === 5 && ($dry['notes'] ?? 0) === 1 && ($dry['attachments'] ?? 0) === 1, 'torrkörning räknar trådar, anteckning och en inkommande bilaga');
hsi(jeab_inkorg_messages() === [] && count(jeab_kontakter_load()) === $beforeContacts && !is_file(jeab_inkorg_dir() . '/helpscout-import.json'), 'torrkörning skriver inget');
hsi(max($sleeps ?: [0]) >= 2.0, 'första listan tar Retry-After');

$dryDisk = jeab_hs_import_run(['mailbox' => 'all', 'limit' => 10, 'budget' => 30, 'disk_min_bytes' => PHP_INT_MAX, 'disk_min_ratio' => 0]);
hsi(!empty($dryDisk['dry']) && ($dryDisk['conversations'] ?? 0) === 4 && ($dryDisk['stopped'] ?? '') !== 'disk', 'torrkörning stoppas inte av disken');
$listsBefore = $state['lists'];
$stopped = jeab_hs_import_run(['mailbox' => 'jarfalla', 'limit' => 1, 'dry' => 0, 'budget' => 30, 'disk_min_bytes' => PHP_INT_MAX, 'disk_min_ratio' => 0]);
hsi(($stopped['stopped'] ?? '') === 'disk' && ($stopped['conversations'] ?? 0) === 0 && !is_dir($tmp . '/inkorg/hs-import/c'), 'importen stannar när ledigt utrymme är under gränsen');
hsi($state['lists'] === $listsBefore && isset($stopped['disk_free_bytes']), 'diskstopp hämtar inte Help Scout och svarar med tal');
hsi(is_int($stopped['disk_free_bytes']) && is_int($stopped['disk_total_bytes']), 'diskstopp innehåller bara tal för utrymmet');

$one = jeab_hs_import_run(['mailbox' => 'jarfalla', 'limit' => 1, 'dry' => 0, 'budget' => 30, 'disk_min_bytes' => 0, 'disk_min_ratio' => 0]);
hsi(empty($one['dry']) && ($one['conversations'] ?? 0) === 1 && ($one['by_company']['jarfalla'] ?? 0) === 1 && ($one['contacts_merged'] ?? 0) === 1, 'första riktiga batchen tar bara Anna och slår ihop');
$cp = jeab_hs_import_checkpoint();
hsi((string) ($cp['mailboxes']['jarfalla']['after_id'] ?? '') === '1001' && (int) ($cp['mailboxes']['jarfalla']['page'] ?? 0) === 1, 'checkpointen minns sista konversationen på sidan');
$cpJson = json_encode($cp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
hsi((string) ($cp['customers']['50'] ?? '') === $annaId && is_string($cpJson) && !str_contains($cpJson, '@') && !is_file(jeab_inkorg_dir() . '/helpscout-import.json'), 'checkpointen mappar bara kund-id till kontakt-id');

$two = jeab_hs_import_run(['mailbox' => 'jarfalla', 'limit' => 1, 'dry' => 0, 'budget' => 30, 'disk_min_bytes' => 0, 'disk_min_ratio' => 0]);
hsi(($two['conversations'] ?? 0) === 1 && ($two['contacts_created'] ?? 0) === 1, 'nästa batch tar första ärendet på sida 2');
$three = jeab_hs_import_run(['mailbox' => 'all', 'limit' => 5, 'dry' => 0, 'budget' => 30, 'disk_min_bytes' => 0, 'disk_min_ratio' => 0]);
hsi(($three['by_company']['jarfalla'] ?? 0) === 1 && ($three['by_company']['malmo'] ?? 0) === 1, 'resten tar egen adress och Malmö');

$msgPath = jeab_inkorg_dir() . '/messages.json';
$thrPath = jeab_inkorg_dir() . '/threads.json';
$liveBlob = (is_file($msgPath) ? (string) file_get_contents($msgPath) : '') . (is_file($thrPath) ? (string) file_get_contents($thrPath) : '');
hsi(!str_contains($liveBlob, 'helpscout_import') && !str_contains($liveBlob, 'anna@post.se'), 'importen skriver inte messages.json eller threads.json');
$stored = '';
foreach (jeab_hs_import_list_threads() as $stub) {
    $conv = jeab_hs_import_read_conv((string) $stub['id']);
    if (is_array($conv)) {
        $stored .= (string) json_encode($conv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
hsi($stored !== '' && !str_contains($stored, 'NOT-STORED-TAK') && !str_contains($stored, 'OUTGOING-NOT-IMPORTED') && !str_contains($stored, 'hs-test-secret'), 'bilagebyte och nyckel lagras inte');
hsi(substr_count($stored, 'anna@post.se') >= 1 && !str_contains($stored, 'personnummer'), 'kundtext finns, personnummer gör inte');
$indexRaw = (string) json_encode(jeab_hs_import_index()['by_id'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
hsi(!str_contains($indexRaw, 'Tidigare meddelande') && !str_contains($indexRaw, 'helpscout.net'), 'indexet har varken citatet eller en Help Scout-adress');
$prev = false;
if (is_dir(jeab_hs_import_root())) {
    $pit = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(jeab_hs_import_root(), FilesystemIterator::SKIP_DOTS));
    foreach ($pit as $pf) {
        if (str_ends_with($pf->getFilename(), '.prev')) {
            $prev = true;
        }
    }
}
hsi($prev === false && !is_file(jeab_inkorg_dir() . '/helpscout-import.json.prev') && !is_file(jeab_hs_import_root() . '/index.jsonl'), 'importen skriver ingen .prev');

$byConv = [];
foreach (jeab_hs_import_list_threads() as $stub) {
    $full = jeab_hs_import_open_thread((string) $stub['id']);
    if (!is_array($full)) {
        continue;
    }
    foreach ($full['messages'] as $m) {
        $byConv[(string) ($m['hs_conversation_id'] ?? '')][] = $m;
    }
}
hsi(isset($byConv['1001'], $byConv['1002'], $byConv['1003'], $byConv['2001']) && !isset($byConv['1004']), 'alla fyra konversationer är inne, inte den senare');
$annaMsgs = $byConv['1001'];
usort($annaMsgs, fn($a, $b) => strcmp((string) $a['received_at'], (string) $b['received_at']));
hsi(count($annaMsgs) === 3, 'raden, svaret och anteckningen är inne, statusraden är inte det');
$clean = jeab_inkorg_clean_message($annaMsgs[0]);
hsi(str_contains($clean, 'Hej, jag vill ha batteri på taket') && !str_contains($clean, 'Tidigare meddelande') && !str_contains($clean, 'Skickat från min iPhone'), 'inkorgen rensar citat och boilerplate');
hsi(str_contains((string) $annaMsgs[0]['body'], 'Tidigare meddelande'), 'originalet ligger kvar i lagringen');
hsi(!str_contains((string) $annaMsgs[1]['body'], 'Citerad utgående historik') && !str_contains((string) $annaMsgs[1]['body'], 'Skickat från min iPhone'), 'utgående text rensas innan den sparas');
hsi(!empty($annaMsgs[2]['internal_note']) && str_contains((string) $annaMsgs[2]['body'], 'Intern koll av taket'), 'anteckningen är en intern anteckning');
hsi(count((array) $annaMsgs[0]['attachments']) === 1 && (array) $annaMsgs[1]['attachments'] === [] && !isset($annaMsgs[0]['attachments'][0]['data']), 'bara inkommande bilaga, utan innehåll');
hsi((string) $annaMsgs[0]['source'] === 'helpscout_import' && (string) $annaMsgs[0]['company'] === 'jarfalla', 'källan och bolaget sitter på mejlet');

hsi(jeab_inkorg_threads() === [], 'den vanliga inkorgen är orörd');
$threadsNow = jeab_hs_import_list_threads();
$openAnna = null;
$openBo = null;
$openMalmo = null;
foreach ($threadsNow as $t) {
    $sub = (string) ($t['first']['subject'] ?? '');
    if ($sub === 'Batteri på taket') {
        $openAnna = $t;
    }
    if ($sub === 'Stängd laddbox') {
        $openBo = $t;
    }
    if ($sub === 'Malmö pump') {
        $openMalmo = $t;
    }
}
$openAnna = is_array($openAnna) ? jeab_hs_import_open_thread((string) $openAnna['id']) : null;
$openBo = is_array($openBo) ? jeab_hs_import_open_thread((string) $openBo['id']) : null;
$openMalmo = is_array($openMalmo) ? jeab_hs_import_open_thread((string) $openMalmo['id']) : null;
hsi(is_array($openAnna) && $openAnna['status'] === 'open' && $openAnna['import_source'] === 'helpscout_import' && $openAnna['company'] === 'jarfalla' && $openAnna['contact_id'] === $annaId, 'Annas ärende är öppet, Järfälla och kopplat');
hsi(is_string(jeab_hs_import_conv_path((string) $openAnna['id'])) && is_array(jeab_hs_import_read_conv((string) $openAnna['id'])) && !is_file((string) jeab_hs_import_conv_path((string) $openAnna['id'])), 'ärendet ligger i SQL');
hsi(is_array($openBo) && $openBo['status'] === 'closed' && $openBo['company'] === 'jarfalla', 'stängt i Help Scout blir stängt');
hsi(is_array($openMalmo) && $openMalmo['status'] === 'waiting_customer' && $openMalmo['company'] === 'malmo', 'Malmö pending blir väntar kund');
$jarfallaHits = array_values(array_filter($threadsNow, fn($t) => jeab_inkorg_thread_matches($t, ['company' => 'jarfalla', 'status' => ['open', 'waiting_customer', 'closed']])));
$jarfallaSubjects = array_map(fn($t) => (string) $t['first']['subject'], $jarfallaHits);
hsi(in_array('Batteri på taket', $jarfallaSubjects, true) && !in_array('Malmö pump', $jarfallaSubjects, true), 'Malmö syns inte i Järfällas inkorg');

$contacts = jeab_kontakter_load();
$emails = [];
foreach ($contacts as $c) {
    foreach ((array) ($c['emails'] ?? []) as $em) {
        $emails[] = mb_strtolower((string) (is_array($em) ? ($em['value'] ?? $em['email'] ?? '') : $em));
    }
    if (isset($c['email'])) {
        $emails[] = mb_strtolower((string) $c['email']);
    }
}
$blob = (string) file_get_contents(jeab_data_dir() . '/kontakter.json');
hsi(substr_count($blob, 'anna@post.se') === 1 && str_contains($blob, 'bo@post.se') && str_contains($blob, 'mia@post.se') && !str_contains($blob, 'kontakt@jarfalla.example.test'), 'Anna slås ihop, Bo och Mia skapas, egen adress blir ingen kontakt');
hsi((string) (jeab_kontakt_get($annaId)['city'] ?? '') === 'Sparadort', 'befintlig ort skrivs inte över');
hsi(count($contacts) === $beforeContacts + 2, 'två nya kontakter, inte fler');

$countBefore = 0;
foreach (jeab_hs_import_list_threads() as $stub) {
    $countBefore += (int) ($stub['message_count'] ?? 0);
}
$cp = jeab_hs_import_checkpoint();
$cp['mailboxes']['jarfalla']['page'] = 1;
$cp['mailboxes']['jarfalla']['after_id'] = '';
$cp['mailboxes']['jarfalla']['backfill_done'] = false;
jeab_hs_import_checkpoint_update(static function (array &$d) use ($cp): bool {
    $d = $cp;
    return true;
});
$again = jeab_hs_import_run(['mailbox' => 'jarfalla', 'limit' => 5, 'dry' => 0, 'budget' => 30, 'disk_min_bytes' => 0, 'disk_min_ratio' => 0]);
$countAfter = 0;
foreach (jeab_hs_import_list_threads() as $stub) {
    $countAfter += (int) ($stub['message_count'] ?? 0);
}
hsi(($again['duplicates'] ?? 0) > 0 && $countAfter === $countBefore, 'samma Help Scout-id importeras inte igen');

$status = jeab_hs_import_hook(['action' => 'helpscout.import_status']);
$usage = jeab_hs_import_store_usage();
hsi(!empty($status['ok']) && (int) ($status['space']['import_bytes'] ?? 0) === (int) $usage['bytes'] && (int) ($status['space']['import_files'] ?? 0) === (int) $usage['files'] && (int) ($status['space']['bytes_per_conversation'] ?? 0) > 0 && (int) ($status['space']['projected_bytes_20000'] ?? 0) === (int) $status['space']['bytes_per_conversation'] * 20000, 'status räknar utrymme per ärende');
hsi(!str_contains((string) json_encode($status), 'anna@post.se') && !str_contains((string) json_encode($status), 'hs-test-secret'), 'statussvaret har varken kunddata eller nyckel');

$state['mode'] = 'incremental';
$state['lists'] = 5;
$inc = jeab_hs_import_run(['mailbox' => 'all', 'limit' => 5, 'dry' => 0, 'since' => 1, 'budget' => 30, 'disk_min_bytes' => 0, 'disk_min_ratio' => 0]);
hsi(($inc['mode'] ?? '') === 'incremental' && ($inc['conversations'] ?? 0) === 1, 'since tar bara det som ändrats efter förra importen');
$newCases = 0;
foreach (jeab_hs_import_list_threads() as $stub) {
    if ((string) ($stub['first']['subject'] ?? '') === 'Nytt efter import') {
        $newCases++;
    }
}
hsi($newCases === 1, 'det nya ärendet sparas en gång');

$msgId = (string) $annaMsgs[0]['id'];
jeab_inkorg_update($msgId, function (array $m): array {
    $m['labels'] = ['type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 100, 'intent' => 'kop', 'offer' => 'batteri', 'source' => 'manual', 'reason' => '', 'needs_review' => false];
    return $m;
});
jeab_auto_settings_save(['auto_quote_enabled' => 1, 'auto_trash_spam' => 0, 'auto_quote_only_from' => 'anna@post.se', 'auto_min_confidence' => 50, 'tpl' => []]);
jeab_rebook_settings_save(['rebook_enabled' => 1]);
$remindersBefore = count(jeab_list_reminders(jeab_store(), true));
$draftsBefore = 0;
foreach (jeab_inkorg_threads_meta() as $meta) {
    $draftsBefore += count((array) ($meta['drafts'] ?? []));
}
$sent = false;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use (&$sent): array {
    if ($method !== 'GET' || str_contains($url, 'sendMail')) {
        $sent = true;
    }
    if (str_contains($url, 'internetMessageId')) {
        return ['code' => 200, 'body' => ['value' => [['id' => 'graph-msg-1']]]];
    }
    if (str_contains($url, '/attachments?')) {
        return ['code' => 200, 'body' => ['value' => [['id' => 'att-g-1', 'name' => 'tak.jpg', 'size' => 8, 'contentType' => 'image/jpeg']]]];
    }
    if (str_contains($url, '/attachments/att-g-1')) {
        return ['code' => 200, 'body' => ['contentBytes' => base64_encode('TAKJPEG')]];
    }
    return ['code' => 404, 'body' => []];
};
$auto = jeab_auto_on_message($msgId, true);
$quoteThread = jeab_hs_import_open_thread((string) $openAnna['id']);
$quote = jeab_auto_quote($quoteThread, jeab_inkorg_get($msgId), jeab_auto_settings(), ['lead' => true, 'offer' => 'batteri']);
$rebook = jeab_rebook_on_message($msgId, true);
$lead = jeab_lead_process($quoteThread, true);
$draftsAfter = 0;
foreach (jeab_inkorg_threads_meta() as $meta) {
    $draftsAfter += count((array) ($meta['drafts'] ?? []));
}
hsi(($auto['why'] ?? '') === 'importerat ärende' && ($quote['why'] ?? '') === 'importerat ärende' && ($rebook['why'] ?? '') === 'importerat ärende' && ($lead['skipped'] ?? '') === 'helpscout_import', 'import triggar ingen offert, ombokning eller lead');
$importNotes = '';
foreach ((array) (($quoteThread['drafts'] ?? [])) as $draft) {
    if (is_array($draft) && ($draft['kind'] ?? '') === 'note') {
        $importNotes .= (string) ($draft['body'] ?? '') . "\n";
    }
}
$importMeta = jeab_inkorg_thread_meta((string) ($quoteThread['id'] ?? ''));
foreach ((array) ($importMeta['drafts'] ?? []) as $draft) {
    if (is_array($draft) && ($draft['kind'] ?? '') === 'note') {
        $importNotes .= (string) ($draft['body'] ?? '') . "\n";
    }
}
hsi(str_contains($importNotes, 'Offert skickades inte automatiskt: importerat ärende'), 'import antecknar att offerten inte gick');
hsi(count(jeab_list_reminders(jeab_store(), true)) === $remindersBefore + 1 && $sent === false, 'import skickar inget och lämnar en påminnelse');

$filesBefore = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile()) {
        $filesBefore[] = $f->getPathname();
    }
}
sort($filesBefore);
$state['data'] = 0;
$read = jeab_hs_import_read_attachment(jeab_inkorg_get($msgId), (array) jeab_inkorg_get($msgId)['attachments'][0]);
hsi(!empty($read['ok']) && ($read['bytes'] ?? '') === 'TAKJPEG' && $state['data'] === 0, 'bilagan hämtas från Microsoft 365 och inte från Help Scout');
$missAtt = (array) jeab_inkorg_get($msgId)['attachments'][0];
$missAtt['name'] = 'saknas.jpg';
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (): array {
    return ['code' => 404, 'body' => []];
};
$miss = jeab_hs_import_read_attachment(jeab_inkorg_get($msgId), $missAtt);
hsi(!empty($miss['ok']) && ($miss['bytes'] ?? '') === 'HSBYTES' && $state['data'] === 1, 'saknas mejlet i Microsoft 365 hämtas bilagan från Help Scout');
$big = $missAtt;
$big['size'] = JEAB_HS_IMPORT_MAX_BYTES + 1;
$calls = $state['data'];
$bigRead = jeab_hs_import_read_attachment(jeab_inkorg_get($msgId), $big);
hsi(empty($bigRead['ok']) && ($bigRead['error'] ?? '') === 'too_big' && $state['data'] === $calls, 'för stor bilaga hämtas inte');
$filesAfter = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile()) {
        $filesAfter[] = $f->getPathname();
    }
}
sort($filesAfter);
hsi($filesBefore === $filesAfter, 'hämtningen sparar inget på disk');
set_error_handler(static function (int $severity, string $message): bool {
    return str_contains($message, 'headers already sent') || str_contains($message, 'Cannot set response code');
});
ob_start();
jeab_hs_import_send_attachment($msgId, 0);
$locked = ob_get_clean();
restore_error_handler();
hsi($locked === "Logga in.\n", 'bilagelänken kräver inloggad personal');

$annaIdThread = (string) $openAnna['id'];
$annaStored = jeab_hs_import_read_conv($annaIdThread);
$annaPdo = jeab_hs_import_store_pdo();
hsi($annaPdo instanceof PDO && is_array($annaStored), 'arkivraden finns i SQL');
$annaPdo->prepare('DELETE FROM jeab_hs_import_conv WHERE id = ?')->execute([$annaIdThread]);
$still = false;
foreach (jeab_hs_import_list_threads() as $stub) {
    if ((string) ($stub['first']['subject'] ?? '') === 'Batteri på taket') {
        $still = true;
    }
}
hsi($still && jeab_hs_import_open_thread($annaIdThread) === null, 'listan läser indexet och öppning kräver konversationen');
jeab_hs_import_save_conv($annaStored);
jeab_inkorg_mark_read((string) $openAnna['id'], 'saml');
$thrAfter = is_file($thrPath) ? (string) file_get_contents($thrPath) : '';
hsi(!str_contains($thrAfter, (string) $openAnna['id']), 'läst-markering skriver inte threads.json');
$disk = jeab_system_disk();
$diskJson = (string) json_encode($disk);
hsi(is_int($disk['disk_free_bytes']) && is_int($disk['disk_total_bytes']) && is_float($disk['disk_free_ratio']) && is_int($disk['data']['bytes']) && is_int($disk['data']['files']) && is_int($disk['inkorg']['bytes']) && is_int($disk['inkorg']['files']), 'system.disk rapporterar tal');
hsi(!str_contains($diskJson, 'anna@') && !str_contains($diskJson, 'tak.jpg') && !str_contains($diskJson, 'hs-import'), 'system.disk har inga filnamn eller kunddata');
hsi(str_contains($hookSrc, 'system.disk'), 'hook.php tar emot system.disk');
$pageSlice = jeab_hs_import_page([1, 2, 3], 2, 2);
hsi($pageSlice['rows'] === [3] && $pageSlice['pages'] === 2, 'importlistan delas i sidor');

$_GET = ['p' => 'inkorg', 'view' => 'v-information', 'id' => (string) $openMalmo['id']];
$malmoHtml = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
hsi(!str_contains($malmoHtml, 'Malmö pump') && str_contains($malmoHtml, 'Inget ärende valt'), 'öppet Malmö-ärende visas inte i Järfälla');

$_GET = ['p' => 'inkorg', 'view' => 'v-information', 'id' => (string) $openAnna['id']];
$html = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
hsi(str_contains($html, 'Batteri på taket') && str_contains($html, 'Hej, jag vill ha batteri på taket') && str_contains($html, 'intern anteckning') && str_contains($html, 'Intern koll av taket'), 'ärendet visar text och intern anteckning');
hsi(str_contains($html, 'Visa original') && str_contains($html, 'is-note') && str_contains($html, 'inkorg-bilaga') && str_contains($html, 'tak.jpg'), 'Visa original och bilagelänk finns');
hsi(!str_contains($html, 'helpscout.net') && !str_contains($html, 'helpscout.com') && !str_contains(strtolower($html), 'help scout') && !str_contains($html, 'conversations/1001'), 'sidan har ingen Help Scout-länk');
hsi(str_contains($html, 'Kontaktregister') && str_contains($html, 'p=kontakter'), 'ärendet pekar på kontaktregistret');
hsi(str_contains($html, 'id=' . rawurlencode($annaId)), 'ärendet med contact_id öppnar kontakten');
hsi(!str_contains($html, 'inkorg-kontakter'), 'inkorgen länkar inte till den gamla kontaktsidan');
hsi(!str_contains($html, 'p=kontakter">Kontakter'), 'inställningarna har ingen Kontakter-länk');

$store = jeab_store();
$_GET = ['p' => 'kontakter', 'id' => $annaId];
$contactHtml = (string) include dirname(__DIR__) . '/src/pages/kontakter.php';
hsi(str_contains($contactHtml, 'Anna Alm') && str_contains($contactHtml, 'Batteri på taket') && str_contains($contactHtml, 'p=inkorg') && !str_contains($contactHtml, 'helpscout'), 'kontakten visar ärendet utan Help Scout');

echo "helpscout import: ok\n";
