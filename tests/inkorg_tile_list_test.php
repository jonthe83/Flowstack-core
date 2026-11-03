<?php

declare(strict_types=1);

/**
 * Vykort och lista använder samma rader: status, bolag och Help Scout-kopior.
 * Första laddningen med bara ?co räknar samma box. Ingen kunddata.
 */

$mode = $argv[1] ?? '';
$root = dirname(__DIR__);
$tmp = $argv[2] ?? '';
if ($mode === '' || $tmp === '') {
    $tmp = sys_get_temp_dir() . '/jeab-ixtile-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/inkorg', 0775, true);
    mkdir($tmp . '/secrets', 0775, true);
    file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
        'timezone' => 'Europe/Stockholm',
        'db' => [
            'driver' => 'sqlite',
            'sqlite_path' => $tmp . '/app.sqlite',
            'json_path' => $tmp . '/store.json',
        ],
    ], true) . ";\n");
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';

function tl(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function tl_session(?string $email = null): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $email = $email ?: 'saljare3@jarfalla.example.test';
    if ($email === 'demo@example.test') {
        require_once dirname(__DIR__) . '/tests/sam_l_login.php';
        jeab_test_login_sam_l();
    } else {
        unset($_COOKIE['jeab_auth']);
        $_SESSION['jeab_email'] = $email;
    }
    $_SESSION['jeab_inkorg_box'] = 'jarfalla';
}

if ($mode === 'part') {
    require $root . '/src/layout.php';
    $actor = (string) ($argv[7] ?? '');
    tl_session($actor !== '' ? $actor : null);
    $which = (string) ($argv[3] ?? 'jarfalla');
    $get = [
        'p' => 'inkorg',
        'view' => (string) ($argv[4] ?? 'v-inkorg'),
        'scope' => (string) ($argv[5] ?? 'egna'),
        'ix_part' => (string) ($argv[6] ?? 'list'),
    ];
    if ($which === 'co') {
        $get['co'] = 'jarfalla';
    } else {
        $get['box'] = $which;
        $get['co'] = $which;
    }
    $_GET = $get;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    unset($GLOBALS['_JEAB_INKORG_BOX_PIN'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP'], $GLOBALS['_JEAB_HS_IMPORT_INDEX']);
    echo include $root . '/src/pages/inkorg.php';
    exit(0);
}

require $root . '/src/layout.php';
tl_session();
$pageJs = (string) file_get_contents($root . '/src/pages/inkorg.php');
tl(str_contains($pageJs, "jeab_asset_url('assets/js/inkorg-nav.js')"), 'jeab_asset_url är kvar');
$warmAt = strpos($pageJs, 'function ixWarm()');
$warmBody = $warmAt === false ? '' : substr($pageJs, $warmAt, 500);
tl($warmAt !== false && str_contains($warmBody, 'ixLoadThread(url, false)') && !str_contains($warmBody, "set('ix_part', 'all')"), 'första laddningen hämtar bara den öppna tråden');
tl(str_contains($pageJs, 'jeabInkorgAddressBox'), 'klienten läser box eller co');

$node = getenv('NODE') ?: 'node';
$nodeFile = $tmp . '/tile-list.js';
file_put_contents($nodeFile, <<<'JS'
const assert = require('assert');
const nav = require(process.argv[2]);
const co = new URLSearchParams('p=inkorg&co=jarfalla');
assert.strictEqual(nav.jeabInkorgAddressBox(co), 'jarfalla');
const both = new URLSearchParams('box=malmo&co=jarfalla');
assert.strictEqual(nav.jeabInkorgAddressBox(both), 'malmo');
const first = nav.jeabInkorgCountsDecision(
  { gen: 1, box: 'jarfalla', left: 10 },
  { gen: 1, locationBox: 'jarfalla', domBox: 'jarfalla' },
  { fresh: 1, counts: { 'v-inkorg': 2, 'v-alla': 3 }, box: 'jarfalla' }
);
assert.strictEqual(first.paint, true);
assert.strictEqual(first.reason, 'ok');
JS);
exec(escapeshellarg($node) . ' ' . escapeshellarg($nodeFile) . ' ' . escapeshellarg($root . '/assets/js/inkorg-nav.js') . ' 2>&1', $nodeOut, $nodeCode);
tl($nodeCode === 0, 'co utan box räknas som det bolaget ' . implode(' ', $nodeOut));

$pdo = jeab_pdo_connect_sqlite();
tl(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
$store = jeab_inkorg_pdo_store();
tl($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');

$seed = [
    ['id' => 'open-jf', 'company' => 'jarfalla', 'status' => 'open', 'manual' => [], 'name' => 'Öppen Järfälla', 'source' => 'graph-jarfalla', 'to' => 'kontakt@jarfalla.example.test'],
    ['id' => 'stale-jf', 'company' => 'jarfalla', 'status' => 'open', 'manual' => [], 'name' => 'Kolumnen stängd', 'source' => 'graph-jarfalla', 'to' => 'kontakt@jarfalla.example.test'],
    ['id' => 'shut-jf', 'company' => 'jarfalla', 'status' => 'closed', 'manual' => [], 'name' => 'Verkligen stängd', 'source' => 'graph-jarfalla', 'to' => 'kontakt@jarfalla.example.test'],
    ['id' => 'svc-jf', 'company' => 'jarfalla', 'status' => 'open', 'manual' => ['service'], 'name' => 'Service Järfälla', 'source' => 'graph-jarfalla', 'to' => 'kontakt@jarfalla.example.test'],
];
foreach ($seed as $i => $row) {
    $store->upsertMessage($row['id'] . 'm', [
        'id' => $row['id'] . 'm',
        'thread_id' => $row['id'],
        'from' => $row['id'] . '@exempel.test',
        'from_name' => $row['name'],
        'to' => $row['to'],
        'subject' => $row['name'],
        'body' => $row['name'],
        'received_at' => sprintf('2024-06-%02dT10:00:00+00:00', $i + 1),
        'source' => $row['source'],
        'labels' => ['type' => 'Support', 'intent' => 'support', 'confidence' => 90, 'product' => 'batteri'],
    ]);
    $store->upsertThread($row['id'], [
        'status' => $row['status'],
        'tags' => [],
        'manual_tags' => $row['manual'],
        'assignee' => '',
        'read' => [],
        'company' => $row['company'],
        'updated_at' => sprintf('2024-06-%02dT10:00:00+00:00', $i + 1),
    ]);
}
$store->clearListStamp();
$GLOBALS['_JEAB_INKORG_PART'] = 'counts';
jeab_inkorg_sql_list_sync($store, 'seed-tile');
unset($GLOBALS['_JEAB_INKORG_PART']);
$updated = $pdo->prepare('UPDATE inkorg_list SET list_status = ? WHERE id = ?')->execute(['closed', 'stale-jf']);
tl($updated, 'list_status kan sättas utan att JSON-status ändras');
$check = $pdo->query("SELECT list_status, json_extract(row_json, '$.status') FROM inkorg_list WHERE id = 'stale-jf'")->fetch(PDO::FETCH_NUM);
tl(is_array($check) && $check[0] === 'closed' && $check[1] === 'open', 'kolumnen är stängd och JSON är öppen');

$hid = static fn (string $seed): string => 'hsi-' . substr(hash('sha256', $seed), 0, 16);
$append = static function (string $id, ?string $status, string $subject, array $tags, string $type = 'Ny lead'): void {
    $row = [
        'id' => $id,
        'company' => 'malmo',
        'status_source' => 'helpscout',
        'subject' => $subject,
        'from' => 'kund.' . $id . '@exempel.test',
        'from_name' => 'Malmö Kund',
        'snippet' => $subject,
        'labels' => ['type' => $type],
        'assignee' => '',
        'deleted' => false,
        'import_source' => 'helpscout_import',
        'updated_at' => '2024-06-08T10:00:00+00:00',
        'tags' => $tags,
        'message_ids' => [],
    ];
    if ($status !== null) {
        $row['status'] = $status;
    }
    tl(jeab_hs_import_index_append($row), 'Help Scout-kopia ' . $subject . ' skrivs');
};
$append($hid('kim-batteri'), 'open', 'Kim batteri', ['batteri']);
$append($hid('kim-laddare'), 'open', 'Kim laddare', ['elbilsladdare']);
$append($hid('status-saknas'), null, 'Status saknas', ['batteri']);
$append($hid('sol-oppen'), 'open', 'Sol öppen', ['sol']);
$append($hid('sol-batteri'), 'open', 'Sol och batteri', ['sol', 'batteri']);
$append($hid('support-batteri'), 'open', 'Support batteri', ['batteri'], 'Support');
$append($hid('stangt-batteri'), 'closed', 'Stängt batteri', ['batteri']);
$append($hid('sol-arkiv'), 'closed', 'Sol arkiv', ['sol']);
unset($GLOBALS['_JEAB_HS_IMPORT_INDEX']);

function tl_part(string $box, string $view, string $part, string $scope = 'egna', string $email = ''): array
{
    global $tmp;
    $cmd = [PHP_BINARY, __FILE__, 'part', $tmp, $box, $view, $scope, $part];
    if ($email !== '') {
        $cmd[] = $email;
    }
    $pipes = [];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    tl(is_resource($proc), $part . ' ' . $box . ' startade');
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    tl($code === 0, $part . ' ' . $box . ' ' . $view . ' avslutade rent ' . trim((string) $err));
    $jsonAt = strpos((string) $out, '{');
    $payload = json_decode($jsonAt === false ? '' : substr((string) $out, $jsonAt), true);
    tl(is_array($payload), $part . ' ' . $box . ' är JSON');

    return $payload;
}

function tl_same(string $box, string $view, string $scope = 'egna'): array
{
    $email = $box === 'malmo' ? 'demo@example.test' : '';
    $list = tl_part($box, $view, 'list', $scope, $email);
    $counts = tl_part($box, $view, 'counts', $scope, $email);
    $tile = (int) (($counts['counts'] ?? [])[$view] ?? -1);
    $total = (int) ($list['total'] ?? -2);
    tl($tile === $total, $box . ' ' . $view . ' vykort ' . $tile . ' = lista ' . $total);
    $rows = substr_count((string) ($list['html'] ?? ''), 'class="item ix-it');
    tl($rows === $total, $box . ' ' . $view . ' rader ' . $rows . ' = total ' . $total);
    tl((string) ($list['range'] ?? '') === (string) $total, $box . ' ' . $view . ' rubriken visar ' . $total . ', fick ' . (string) ($list['range'] ?? ''));
    tl((string) ($counts['box'] ?? '') === ($box === 'co' ? 'jarfalla' : $box), $box . ' counts svarar med rätt box');

    return $list;
}

$inkorg = tl_same('jarfalla', 'v-inkorg');
tl(str_contains((string) $inkorg['html'], 'Öppen Järfälla'), 'öppen rad syns');
tl(str_contains((string) $inkorg['html'], 'Kolumnen stängd'), 'JSON-öppen rad syns trots stängd list_status');
tl(!str_contains((string) $inkorg['html'], 'Verkligen stängd'), 'stängd rad är inte i Inkorg');
tl(!str_contains((string) $inkorg['html'], 'Service Järfälla'), 'Service är inte i Inkorg');
tl(!str_contains((string) $inkorg['html'], 'Kim batteri'), 'Malmö-kopian läcker inte till Järfälla');
$alla = tl_same('jarfalla', 'v-alla');
tl(str_contains((string) $alla['html'], 'Service Järfälla'), 'Alla visar Service');
tl((int) $alla['total'] > (int) $inkorg['total'], 'Alla har fler rader än Inkorg');
tl_same('jarfalla', 'v-service');
$co = tl_same('co', 'v-inkorg');
tl((int) $co['total'] === (int) $inkorg['total'], 'co utan box har samma Inkorg-lista');
$warmed = jeab_inkorg_tick_warm();
tl(!empty($warmed['ok']) && jeab_inkorg_import_cached('malmo'), 'tick skriver Malmö-cachen i MySQL innan listan');
$malmo = tl_same('malmo', 'v-inkorg', 'allas');
tl(str_contains((string) $malmo['html'], 'Kim batteri'), 'öppet batteriärende till Kim syns');
tl(str_contains((string) $malmo['html'], 'Kim laddare'), 'öppen elbilsladdare till Kim syns');
foreach (['Status saknas', 'Sol öppen', 'Sol och batteri', 'Support batteri', 'Stängt batteri', 'Sol arkiv'] as $hidden) {
    tl(!str_contains((string) $malmo['html'], $hidden), $hidden . ' ligger inte i Malmös Inkorg');
}
tl((int) $malmo['total'] === 2, 'Malmö Inkorg är bara de två Kim-ärendena');
tl_same('malmo', 'v-alla', 'allas');

$scanBefore = (int) ($GLOBALS['_JEAB_INKORG_IMPORT_SCAN'] ?? 0);
$fullBefore = (int) ($GLOBALS['_JEAB_HS_INDEX_FULL'] ?? 0);
require_once $root . '/tests/sam_l_login.php';
jeab_test_login_sam_l();
unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
$counts = jeab_inkorg_hook(['action' => 'inkorg.counts', 'box' => 'malmo']);
$encoded = json_encode($counts, JSON_UNESCAPED_UNICODE);
tl(is_string($encoded) && !str_contains($encoded, '@') && !str_contains($encoded, 'Kim') && !str_contains($encoded, 'subject'), 'inkorg.counts har ingen persondata');
tl(($counts['ok'] ?? false) === true && ($counts['box'] ?? '') === 'malmo', 'inkorg.counts svarar för Malmö');
$tabs = is_array($counts['tabs'] ?? null) ? $counts['tabs'] : [];
$inkorgTab = is_array($tabs['v-inkorg'] ?? null) ? $tabs['v-inkorg'] : [];
tl((int) ($inkorgTab['total'] ?? -1) === 2 && (int) ($inkorgTab['imports'] ?? -1) === 2 && (int) ($inkorgTab['open_imports'] ?? -1) === 2, 'Inkorg-fliken är två öppna importer');
$allaTab = is_array($tabs['v-alla'] ?? null) ? $tabs['v-alla'] : [];
tl((int) ($allaTab['total'] ?? -1) === 2 && (int) ($allaTab['imports'] ?? -1) === 2 && (int) ($allaTab['open_imports'] ?? -1) === 2, 'Alla-fliken räknar samma Kim-ärenden');
foreach (['v-service', 'v-information'] as $emptyTab) {
    $tab = is_array($tabs[$emptyTab] ?? null) ? $tabs[$emptyTab] : [];
    tl((int) ($tab['total'] ?? -1) === 0 && (int) ($tab['imports'] ?? -1) === 0 && (int) ($tab['open_imports'] ?? -1) === 0, $emptyTab . ' är tom');
}
$histTab = is_array($tabs['v-historik'] ?? null) ? $tabs['v-historik'] : [];
tl((int) ($histTab['imports'] ?? -1) >= 2 && (int) ($histTab['open_imports'] ?? -1) === 0, 'Historik har de stängda kopiorna och inga öppna importer');
tl((int) ($histTab['total'] ?? -1) === (int) ($histTab['imports'] ?? -2), 'Historikens total är importerna');
$scanOnce = (int) ($GLOBALS['_JEAB_INKORG_IMPORT_SCAN'] ?? 0);
tl($scanOnce === $scanBefore, 'räkningen använder importcachen');
unset($GLOBALS['_JEAB_HS_IMPORT_INDEX']);
$again = jeab_inkorg_hook(['action' => 'inkorg.counts', 'box' => 'malmo']);
tl($again === $counts, 'samma stämpel ger samma tal');
tl((int) ($GLOBALS['_JEAB_INKORG_IMPORT_SCAN'] ?? 0) === $scanOnce, 'andra räkningen går inte igenom indexet');
tl((int) ($GLOBALS['_JEAB_HS_INDEX_FULL'] ?? 0) === $fullBefore, 'indexet läses inte om när cachen finns');
$asMeab = jeab_inkorg_hook(['action' => 'inbox.counts', 'box' => 'meab']);
tl(($asMeab['box'] ?? '') === 'malmo' && ($asMeab['tabs']['v-inkorg']['total'] ?? -1) === 2, 'meab räknas som Malmö');
$bad = jeab_inkorg_hook(['action' => 'inkorg.counts', 'box' => 'stockholm']);
tl(($bad['ok'] ?? true) === false && ($bad['error'] ?? '') === 'box' && !isset($bad['tabs']), 'okänd box avslås');
$jarfallaCounts = jeab_inkorg_hook(['action' => 'inkorg.counts', 'box' => 'jarfalla']);
$jfInkorg = is_array($jarfallaCounts['tabs']['v-inkorg'] ?? null) ? $jarfallaCounts['tabs']['v-inkorg'] : [];
tl((int) ($jfInkorg['total'] ?? -1) === (int) $inkorg['total'] && (int) ($jfInkorg['imports'] ?? -1) === 0 && (int) ($jfInkorg['open_imports'] ?? -1) === 0, 'Järfälla räknar sina egna rader utan importarkivet');
tl((int) ($GLOBALS['_JEAB_INKORG_IMPORT_SCAN'] ?? 0) === $scanOnce, 'levande bolag läser inte importarkivet');
$hookSrc = (string) file_get_contents($root . '/hook.php');
$inkorgSrc = (string) file_get_contents($root . '/src/inkorg.php');
tl(str_contains($hookSrc, "str_starts_with(\$jeabHookAction, 'inkorg.')") && str_contains($hookSrc, 'jeab_inkorg_hook_authorized()'), 'inkorg.counts går genom samma Bearer-grind');
tl(str_contains($inkorgSrc, "case 'inbox.counts':") && str_contains($inkorgSrc, "case 'inkorg.counts':"), 'båda namnen är registrerade');

echo "inkorg tile list: ok\n";
