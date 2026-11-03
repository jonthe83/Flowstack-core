<?php

declare(strict_types=1);

/** Kategorietikett i Inkorgen. Bara visning. Inget skickas och inget omklassas. */

$tmp = sys_get_temp_dir() . '/jeab-ixcat-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function lc(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function lc_labels(string $type): array
{
    return [
        'type' => $type,
        'action' => 'svara',
        'priority' => 3,
        'confidence' => 100,
        'source' => 'test',
        'reason' => '',
        'needs_review' => false,
        'add_tags' => [],
    ];
}

function lc_mail(string $id, string $type, string $at, string $subject, array $extra = []): array
{
    $res = jeab_inkorg_ingest([
        'message_id' => '<' . $id . '@example.test>',
        'from' => $extra['from'] ?? ($id . '@example.test'),
        'from_name' => 'Test ' . $id,
        'to' => 'info@jarfalla.example.test',
        'subject' => $subject,
        'body_text' => 'Hej, det här är ett fejkmejl.',
        'source' => (string) ($extra['source'] ?? 'test-ixcat'),
        'test' => true,
        'received_at' => $at,
        'date' => $at,
        'thread_key' => (string) ($extra['thread_key'] ?? ''),
        'preset_labels' => lc_labels($type),
        'internal_note' => !empty($extra['internal_note']),
    ]);
    lc(!empty($res['ok']), $id . ' tas in');

    return $res;
}

function lc_row(string $html, string $subject): string
{
    if (preg_match_all('/<li class="item ix-it[\s\S]*?<\/li>/', $html, $m) !== false) {
        foreach ($m[0] as $li) {
            if (str_contains($li, $subject)) {
                return $li;
            }
        }
    }
    return '';
}

$now = strtotime('2026-10-20T12:00:00+00:00');
lc($now !== false, 'klockan är satt');
$recent = date('c', $now - 2 * 86400);
$edge = date('c', $now - 14 * 86400);
$old = date('c', $now - 20 * 86400);
$importAt = '2026-10-19T08:00:00+00:00';

lc(jeab_inkorg_list_category('Ny lead') === 'Lead', 'Ny lead visas som Lead');
lc(jeab_inkorg_list_category('Support') === 'Support', 'Support behåller namnet');
lc(jeab_inkorg_list_category('Bokning/ombokning') === 'Bokning/ombokning', 'bokning behåller namnet');
lc(jeab_inkorg_list_category('Skräp') === 'Skräp', 'Skräp behåller namnet');
lc(jeab_inkorg_list_category('Information') === 'Information', 'Information behåller namnet');
lc(jeab_inkorg_list_category('Order') === 'Order', 'Order behåller klassningens ord');
lc(jeab_inkorg_list_category('Kräver åtgärd från oss') === 'Kräver åtgärd från oss', 'övrig typ visas som den är');
lc(jeab_inkorg_list_category('Nyhetsbrev/reklam') === 'Skräp', 'gammal typ mappas innan visning');
lc(jeab_inkorg_list_category('') === '', 'tom typ ger ingen etikett');

$lead = lc_mail('lead', 'Ny lead', $recent, 'Lead nyligen');
$info = lc_mail('info', 'Information', $edge, 'Info på gränsen');
$order = lc_mail('order', 'Order', $old, 'Order gammal');
$junk = lc_mail('junk', 'Skräp', $recent, 'Skräp nyligen');
$book = lc_mail('book', 'Bokning/ombokning', $recent, 'Bokning nyligen');
$act = lc_mail('act', 'Kräver åtgärd från oss', $recent, 'Åtgärd nyligen');
$bare = lc_mail('bare', '', $recent, 'Utan kategori');
$noted = lc_mail('noted', 'Support', $old, 'Bara anteckning nyligen');
lc_mail('note', 'Support', $recent, 'Intern rad', [
    'thread_key' => (string) $noted['thread_id'],
    'internal_note' => true,
]);
$out = lc_mail('out', 'Ny lead', $old, 'Utgående nyligen');
lc_mail('reply', 'Ny lead', $recent, 'Utgående nyligen', [
    'thread_key' => (string) $out['thread_id'],
    'source' => 'outbound-jarfalla',
    'from' => 'info@jarfalla.example.test',
]);

$byId = array_column(jeab_inkorg_threads(), null, 'id');
$before = json_encode(array_column($byId, 'labels', 'id'));
lc(jeab_inkorg_list_category_mark($byId[$lead['thread_id']], $now) === 'Lead', 'färskt mejl får Lead');
lc(jeab_inkorg_list_category_mark($byId[$info['thread_id']], $now) === 'Information', 'dag 14 räknas med');
lc(jeab_inkorg_list_category_mark($byId[$order['thread_id']], $now) === 'Order', 'äldre mejl visar ändå typen');
lc(jeab_inkorg_list_category_mark($byId[$junk['thread_id']], $now) === 'Skräp', 'Skräp syns');
lc(jeab_inkorg_list_category_mark($byId[$book['thread_id']], $now) === 'Bokning/ombokning', 'bokning syns med sitt namn');
lc(jeab_inkorg_list_category_mark($byId[$act['thread_id']], $now) === 'Kräver åtgärd från oss', 'övrig kategori syns oförändrad');
lc(jeab_inkorg_list_category_mark($byId[$bare['thread_id']], $now) === '', 'saknad kategori ger ingen etikett');
lc(jeab_inkorg_list_category_mark($byId[$noted['thread_id']], $now) === 'Support', 'anteckning döljer inte Support');
lc(jeab_inkorg_list_category_mark($byId[$out['thread_id']], $now) === 'Lead', 'utgående mejl räknas');
lc(json_encode(array_column(array_column(jeab_inkorg_threads(), null, 'id'), 'labels', 'id')) === $before, 'visningen skriver inte om klassningen');

jeab_inkorg_thread_set((string) $junk['thread_id'], 'closed', null);

$conv = static function (string $cid, string $mailAt, string $type) use ($importAt): array {
    return [
        'id' => jeab_hs_import_thread_key($cid),
        'company' => 'jarfalla',
        'status' => 'closed',
        'import_source' => 'helpscout_import',
        'messages' => [[
            'id' => substr(hash('sha256', $cid), 0, 16),
            'message_id' => 'hs-' . $cid . '@helpscout.net',
            'from' => 'import.kund@example.test',
            'from_name' => 'Import Kund',
            'subject' => 'Import ' . $cid,
            'body' => 'Hej, fejkad import.',
            'date' => $mailAt,
            'received_at' => $mailAt,
            'source' => 'helpscout_import',
            'labels' => lc_labels($type),
        ]],
        'meta' => [
            'status' => 'closed',
            'updated_at' => $importAt,
            'import_source' => 'helpscout_import',
            'closed_at' => '2024-06-15T12:00:00+00:00',
        ],
    ];
};

$oldConv = $conv('501', '2024-03-01T10:00:00+00:00', 'Information');
lc(jeab_hs_import_save_conv($oldConv), 'gammal import sparas');
$oldRow = jeab_hs_import_index_row($oldConv);
lc(!array_key_exists('last_mail_at', $oldRow), 'indexet får inget nytt JSON-fält');
$oldRow['updated_at'] = $importAt;
lc(jeab_hs_import_index_append($oldRow), 'indexrad med importtid skrivs');
$oldStub = jeab_hs_import_stub_thread(jeab_hs_import_index()['by_id'][$oldConv['id']]);
lc(jeab_inkorg_list_category_mark($oldStub, $now) === 'Information', 'gammal HS-import visar typen ändå');

$freshConv = $conv('502', $recent, 'Skräp');
lc(jeab_hs_import_save_conv($freshConv) && jeab_hs_import_index_append(jeab_hs_import_index_row($freshConv)), 'import med färskt mejl sparas');
$freshStub = jeab_hs_import_stub_thread(jeab_hs_import_index()['by_id'][$freshConv['id']]);
lc(jeab_inkorg_list_category_mark($freshStub, $now) === 'Skräp', 'importerad tråd med färskt mejldatum visar kategorin');

$trapId = jeab_hs_import_thread_key('503');
lc(jeab_hs_import_index_append([
    'id' => $trapId,
    'company' => 'jarfalla',
    'status' => 'closed',
    'subject' => 'Import utan mejldatum',
    'from' => 'import.kund@example.test',
    'from_name' => 'Import Kund',
    'snippet' => 'Hej',
    'updated_at' => $importAt,
    'labels' => ['type' => 'Ny lead', 'action' => 'svara', 'priority' => 1],
    'import_source' => 'helpscout_import',
    'message_count' => 1,
    'deleted' => false,
]), 'indexrad utan last_mail_at skrivs');
unset($GLOBALS['_JEAB_HS_IMPORT_INDEX']);
$trap = jeab_hs_import_stub_thread(jeab_hs_import_index()['by_id'][$trapId]);
lc((string) ($trap['updated_at'] ?? '') === $importAt && jeab_inkorg_list_category_mark($trap, $now) === 'Lead', 'importtid hindrar inte Lead');

$store = jeab_store();
$noteOnly = jeab_create_reminder($store, 'Bara länk', jeab_today_start(), null, null, [
    'customer' => 'Bara Lank',
    'email' => 'bara.lank@example.test',
    'company' => 'jarfalla',
    'note' => 'mail: https://flowstack.example.test/index.php?p=inkorg&id=old-case',
]);
lc(trim((string) ($noteOnly['thread_id'] ?? '')) === 'old-case', 'en mail-länk sparar thread_id direkt');
$freshRem = jeab_create_reminder($store, 'Test Lead – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Test Lead',
    'email' => 'lead@example.test',
    'company' => 'jarfalla',
    'thread_id' => (string) $lead['thread_id'],
    'note' => 'mail: https://flowstack.example.test/index.php?p=inkorg&id=' . rawurlencode((string) $lead['thread_id']),
]);
lc(trim((string) ($freshRem['thread_id'] ?? '')) === (string) $lead['thread_id'], 'ny påminnelse sparar tråden i SQL');
$legacy = jeab_create_reminder($store, 'Gammal rad', jeab_today_start(), null, null, [
    'customer' => 'Gammal Rad',
    'email' => 'gammal@example.test',
    'company' => 'jarfalla',
]);
$store->saveReminder((int) $legacy['id'], [
    'note' => 'mail: https://flowstack.example.test/index.php?p=inkorg&id=' . rawurlencode((string) $order['thread_id']),
    'thread_id' => '',
]);
$legacy = jeab_get_reminder($store, (int) $legacy['id']);
lc(trim((string) ($legacy['thread_id'] ?? '')) === '', 'äldre anteckning lämnar thread_id tom');
lc(in_array('Påminnelse', jeab_inkorg_list_marks($byId[$order['thread_id']], $now), true), 'Påminnelse härleds från anteckningens länk');
lc(in_array('Påminnelse', jeab_inkorg_list_marks($byId[$lead['thread_id']], $now), true), 'Påminnelse härleds från SQL');
$dry = jeab_inkorg_hook(['action' => 'inbox.reminder_labels']);
lc((int) ($dry['dry'] ?? 0) === 1 && (int) ($dry['would_fill'] ?? 0) === 1 && (int) ($dry['filled'] ?? 0) === 0, 'backfill är torr som standard');
lc(trim((string) (jeab_get_reminder($store, (int) $legacy['id'])['thread_id'] ?? '')) === '', 'torrkörning skriver inte');
$report = json_encode($dry);
lc(is_string($report) && !str_contains($report, 'example.test') && !str_contains($report, 'Gammal'), 'rapporten saknar kunddata');
$wet = jeab_inkorg_hook(['action' => 'inbox.reminder_labels', 'dry' => 0]);
lc((int) ($wet['dry'] ?? 1) === 0 && (int) ($wet['filled'] ?? 0) === 1, 'backfill fyller SQL');
lc((string) (jeab_get_reminder($store, (int) $legacy['id'])['thread_id'] ?? '') === (string) $order['thread_id'], 'thread_id är sparad');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
unset($_SESSION['jeab_email'], $_COOKIE['jeab_auth']);

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-oppna'];
$openHtml = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
$leadRow = lc_row($openHtml, 'Lead nyligen');
$orderRow = lc_row($openHtml, 'Order gammal');
$bookRow = lc_row($openHtml, 'Bokning nyligen');
lc($leadRow === '', 'Öppna döljer lead som redan har påminnelse');
lc($orderRow !== '' && str_contains($orderRow, 'class="ix-l1"') && str_contains($orderRow, 'class="ix-l2"') && !str_contains($orderRow, 'class="ix-cat'), 'Öppna visar en kompakt rad utan typchip');
lc($bookRow !== '' && str_contains($bookRow, 'class="ix-sub"') && !str_contains($bookRow, '>Bokning/ombokning<'), 'bokning syns som ärenderad, inte som etikett');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-historik'];
$histHtml = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
$histLead = lc_row($histHtml, 'Lead nyligen');
lc($histLead !== '' && str_contains($histLead, 'class="ix-l1"') && str_contains($histLead, 'class="ix-nm"') && !str_contains($histLead, 'class="ix-cat'), 'Historik visar namn och tid på en rad');
lc($histLead !== '' && !str_contains($histLead, '>Lead<') && !str_contains($histLead, '>Påminnelse<'), 'Historik-raden har inga typchip');
$junkRow = lc_row($histHtml, 'Skräp nyligen');
$importRow = lc_row($histHtml, 'Import 501');
$freshImport = lc_row($histHtml, 'Import 502');
$trapRow = lc_row($histHtml, 'Import utan mejldatum');
lc($junkRow !== '' && str_contains($junkRow, 'class="ix-sub"'), 'Historik visar skräpraden');
lc($importRow !== '' && str_contains($importRow, 'class="ix-l2"'), 'Historik visar importen som rad');
lc($freshImport !== '' && str_contains($freshImport, 'class="ix-l1"'), 'Historik visar färsk import');
lc($trapRow !== '' && str_contains($trapRow, 'class="ix-nm"'), 'Historik visar raden även när bara importtid finns');
lc(!str_contains($histHtml, 'uncategorized_recent'), 'sidan saknar läs-hooken');

$http = sys_get_temp_dir() . '/jeab-ixcat-http-' . bin2hex(random_bytes(3));
mkdir($http, 0700, true);
file_put_contents($http . '/inkorg.html', $openHtml);
file_put_contents($http . '/inkorg.css', (string) file_get_contents(dirname(__DIR__) . '/assets/css/inkorg.css'));
$port = 0;
for ($try = 8121; $try < 8140; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
lc($port > 0, 'en lokal port är ledig');
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $http],
    [1 => ['file', $http . '/server.log', 'a'], 2 => ['file', $http . '/server.log', 'a']],
    $pipes
);
lc(is_resource($server), 'php-servern startade');
$ready = false;
for ($i = 0; $i < 20; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/inkorg.html');
    if (is_string($probe) && str_contains($probe, 'ix-l1')) {
        $ready = true;
        break;
    }
    usleep(100000);
}
lc($ready, 'sidan svarar');
$curlPage = curl_init('http://127.0.0.1:' . $port . '/inkorg.html');
curl_setopt_array($curlPage, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$pageBody = (string) curl_exec($curlPage);
$pageCode = (int) curl_getinfo($curlPage, CURLINFO_HTTP_CODE);
curl_close($curlPage);
$curlCss = curl_init('http://127.0.0.1:' . $port . '/inkorg.css');
curl_setopt_array($curlCss, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$cssBody = (string) curl_exec($curlCss);
$cssCode = (int) curl_getinfo($curlCss, CURLINFO_HTTP_CODE);
curl_close($curlCss);
if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}
lc($pageCode === 200 && str_contains($pageBody, 'class="ix-l1"') && str_contains($pageBody, 'class="ix-l2"'), 'curl ser de kompakta raderna, fick ' . $pageCode);
lc($cssCode === 200 && str_contains($cssBody, '.ix-l1') && str_contains($cssBody, '.ix-prod') && str_contains($cssBody, 'minmax(240px, 340px)'), 'curl ser raden och listbredden, fick ' . $cssCode);

echo "OK  listkategori\n";
