<?php

declare(strict_types=1);

/**
 * Räknarpåsen följer lagringsläget. En påse från JSON-tiden (utan läge, eller
 * räknad i json-läge) får inte ritas när sidan läser SQL. Förut var nyckeln
 * densamma i båda lägena och en sex timmar gammal APCu-påse gav sidan
 * JSON-siffrorna (Öppna 0, Historik 9) fast SQL hade 25. Proben visar per del
 * varifrån räknarna kom. Ingen kunddata.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-ixmode-' . bin2hex(random_bytes(4));
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
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';

function cm(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'allas'];

$pdo = jeab_pdo_connect_sqlite();
cm(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'json'), 'json-flaggan går att sätta');
cm(jeab_inkorg_count_mode() === 'json', 'läget är json');
$menu = jeab_inkorg_fast_scope_menu('allas');
$me = jeab_inkorg_me_id();
$jsonKey = jeab_inkorg_fast_count_key($menu, 'jarfalla', $me);

cm(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
cm(jeab_inkorg_count_mode() === 'sql', 'läget är sql');
$sqlKey = jeab_inkorg_fast_count_key($menu, 'jarfalla', $me);
cm($sqlKey !== $jsonKey, 'räknarnyckeln skiljer json och sql');

$store = jeab_inkorg_pdo_store();
cm($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');
for ($i = 1; $i <= 25; $i++) {
    $id = sprintf('m%02d', $i);
    $store->upsertMessage($id . 'm', [
        'id' => $id . 'm',
        'thread_id' => $id,
        'from' => 'kund' . $i . '@exempel.test',
        'from_name' => 'Kund ' . $i,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Rad ' . $i,
        'body' => 'Kort ' . $i,
        'received_at' => sprintf('2024-06-%02dT10:00:00+00:00', $i),
        'source' => 'graph-jarfalla',
        'labels' => ['type' => 'Support', 'product' => 'batteri'],
    ]);
    $store->upsertThread($id, [
        'status' => 'open',
        'tags' => [],
        'assignee' => '',
        'read' => [],
        'company' => 'jarfalla',
        'updated_at' => sprintf('2024-06-%02dT10:00:00+00:00', $i),
    ]);
}

// Påse från JSON-tiden: inga läges- eller versionsfält, färsk, Öppna 0 och Historik 9.
$legacy = [
    'counts' => ['v-inkorg' => 0, 'v-service' => 0, 'v-information' => 0, 'v-alla' => 0, 'v-historik' => 9],
    'unread' => 0,
    'sources' => [],
    'at' => time() - 30,
];
foreach ([$sqlKey, $jsonKey, md5('count-same|jarfalla|' . $me . '|allas|' . implode(',', array_map(static fn ($v) => (string) ($v['id'] ?? ''), $menu)))] as $k) {
    $store->countCachePut($k, $legacy);
}
cm(jeab_inkorg_fast_count_read($sqlKey) === null, 'påse utan läge läses som miss');
$jsonBag = $legacy + ['mode' => 'json', 'ver' => JEAB_INKORG_COUNT_VER];
$store->countCachePut($sqlKey, $jsonBag);
cm(jeab_inkorg_fast_count_read($sqlKey) === null, 'påse räknad i json läses som miss i sql');

$store->countCachePut($sqlKey, $legacy);
unset($GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP']);
$list = jeab_inkorg_fast_prepare(false, 'list');
$counts = is_array($list['counts'] ?? null) ? $list['counts'] : [];
cm((int) ($counts['v-historik'] ?? -1) !== 9, 'listan ritar inte JSON-tidens Historik 9');
cm((int) ($counts['v-inkorg'] ?? 0) !== 0 || $counts === [], 'listan ritar inte JSON-tidens Öppna 0');
cm((int) $list['total'] === 25, 'listan har 25 ärenden, fick ' . (int) $list['total']);

$written = jeab_inkorg_fast_count_read($sqlKey);
if (is_array($written)) {
    cm((string) ($written['mode'] ?? '') === 'sql', 'ny påse bär läget sql');
}

// Proben: sidans delar i tur och ordning, med spår.
$store->countCachePut($sqlKey, $legacy);
$probe = jeab_inkorg_probe(['parts' => 1, 'person' => 'none', 'box' => 'jarfalla', 'scope' => 'allas', 'view' => 'v-inkorg']);
cm(!empty($probe['ok']) && is_array($probe['parts'] ?? null), 'proben svarar med delar');
$parts = $probe['parts'];
cm(($parts['mode'] ?? '') === 'sql', 'proben visar läget sql');
foreach (['page', 'list', 'all', 'counts', 'app'] as $name) {
    $run = $parts['runs'][$name] ?? null;
    cm(is_array($run) && !empty($run['ok']), 'delen ' . $name . ' körde' . (is_array($run) ? ' ' . (string) ($run['error'] ?? '') : ''));
    cm(is_array($run['trace'] ?? null), 'delen ' . $name . ' har spår');
    $value = is_array($run['value'] ?? null) ? $run['value'] : [];
    $c = is_array($value['counts'] ?? null) ? $value['counts'] : [];
    cm((int) ($c['v-historik'] ?? -1) !== 9, 'delen ' . $name . ' ritar inte Historik 9');
}
cm((string) ($probe['identity']['reason'] ?? '') === 'none', 'proben kan köra utan person');
cm(($parts['who']['me'] ?? 'x') === '', 'utan person är me tomt');

echo "OK  räknarläge\n";
