<?php

declare(strict_types=1);

/**
 * Sidan → lista i SQL-läge. Öppen kolumn ska synas i Inkorgen även när
 * JSON säger raderad, och Historik-cache som är för liten byts mot COUNT.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-pagepath-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/privat', 0775, true);
$GLOBALS['_JEAB_PRIVAT_DIR'] = $tmp . '/privat';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'people' => [
        ['id' => 'BotenFlowy', 'email' => 'boten@malmo.example.test', 'name' => 'Boten Flowy', 'company' => 'malmo', 'alias' => 'boten'],
        ['id' => 'SamL', 'email' => 'demo@example.test', 'name' => 'Sam L', 'company' => 'jarfalla'],
    ],
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

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'scope' => 'allas'];

function pp(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pdo = jeab_pdo_connect_sqlite();
pp(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan');
file_put_contents($tmp . '/privat/inkorg_storage.txt', "sql\n");
$store = jeab_inkorg_pdo_store();
pp($store instanceof JeabPdoInkorgStore, 'SQL-lager');

$store->saveListRow([
    'id' => 'col-open',
    'company' => 'jarfalla',
    'status' => 'waiting_customer',
    'deleted' => true,
    'assignee' => '',
    'updated_at' => '2024-06-02T10:00:00+00:00',
    'customer_email' => 'col@exempel.test',
    'customer_name' => 'Kolumn',
    'labels' => ['type' => 'Ny lead', 'intent' => 'kop'],
    'tags' => ['sol'],
]);
$pdo->prepare('UPDATE inkorg_list SET list_status = ? WHERE id = ?')->execute(['open', 'col-open']);
$pdo->prepare('UPDATE inkorg_list SET row_json = json_set(row_json, \'$.deleted\', json(\'true\')) WHERE id = ?')->execute(['col-open']);
$store->saveListRow([
    'id' => 'shut',
    'company' => 'jarfalla',
    'status' => 'closed',
    'deleted' => false,
    'assignee' => '',
    'updated_at' => '2024-05-01T10:00:00+00:00',
    'customer_email' => 'shut@exempel.test',
    'customer_name' => 'Stängd',
    'labels' => ['type' => 'Support'],
    'tags' => [],
]);
$store->setListStamp('page-path');
jeab_hs_import_store_ensure($pdo);
jeab_hs_import_ensure_list_page($pdo);
$pdo->prepare(
    'INSERT INTO jeab_hs_import_index (id, payload, list_company, list_status, list_at) VALUES (?, ?, ?, ?, ?)'
)->execute(['arkiv-1', '{"id":"arkiv-1","company":"jarfalla","status":"closed"}', 'jarfalla', 'closed', '2024-01-01T00:00:00+00:00']);
$openCol = jeab_inkorg_list_open_count_noddl('jarfalla');
$hist = jeab_inkorg_historik_total_noddl('jarfalla');
pp($openCol >= 1, 'kolumnen har en öppen rad, fick ' . $openCol);
pp($hist >= 2, 'Historik-COUNT är listan plus arkivet, fick ' . $hist);

jeab_inkorg_views();
$menu = jeab_inkorg_fast_scope_menu('allas');
$key = jeab_inkorg_fast_count_key($menu, 'jarfalla', jeab_inkorg_me_id());
jeab_inkorg_fast_count_write($key, [
    'counts' => ['v-inkorg' => 0, 'v-historik' => 1, 'v-solceller' => 2, 'v-information' => 5],
    'unread' => 22,
    'sources' => [],
]);
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
unset($GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP'], $GLOBALS['_JEAB_INKORG_BOX_PIN']);
$prepared = jeab_inkorg_fast_prepare(true, '');
pp((string) ($prepared['vyId'] ?? '') === 'v-inkorg', 'standardvyn är Inkorg');
pp((string) ($prepared['box'] ?? '') === 'jarfalla', 'bolaget är Järfälla');
pp((string) ($prepared['scope'] ?? '') === 'allas', 'listan är allas');
pp((int) ($prepared['total'] ?? 0) >= 1, 'sidans lista är inte tom, fick ' . (int) ($prepared['total'] ?? 0));
pp(!str_contains((string) ($prepared['html'] ?? ''), 'Inget här just nu'), 'listan ritar raden');
pp(str_contains((string) ($prepared['html'] ?? ''), 'data-ix-id="col-open"'), 'den öppna kolumnraden ligger i listan');
pp((int) (($prepared['queues'] ?? [])['oppna'] ?? 0) >= 1, 'Alla öppna följer kolumnen, fick ' . json_encode($prepared['queues'] ?? []));
pp((int) (($prepared['counts'] ?? [])['v-inkorg'] ?? 0) >= 1, 'Inkorg-talet är inte den cachade nollan');
pp((int) (($prepared['counts'] ?? [])['v-historik'] ?? 0) >= $hist, 'Historik-talet är minst COUNT, fick ' . (int) (($prepared['counts'] ?? [])['v-historik'] ?? 0));

$probe = jeab_inkorg_probe(['person' => 'BotenFlowy', 'email' => 'boten@malmo.example.test']);
$encoded = json_encode($probe, JSON_UNESCAPED_UNICODE);
pp(is_string($encoded) && !str_contains($encoded, 'boten@') && !str_contains($encoded, 'col@exempel'), 'proben lämnar inte ut e-post');
pp((string) ($probe['storage']['mode'] ?? '') === 'sql', 'proben ser sql-läget');
pp((string) ($probe['storage']['file'] ?? '') === 'sql', 'proben ser filflaggan');
pp((string) ($probe['identity']['person_id'] ?? '') === 'BotenFlowy', 'proben simulerar Boten');
pp((int) ($probe['page']['total'] ?? 0) >= 1 && empty($probe['page']['empty']), 'probens sidväg har listan');
pp((string) ($probe['page']['box'] ?? '') === 'jarfalla' && (string) ($probe['page']['vy'] ?? '') === 'v-inkorg', 'proben ritar Järfällas Inkorg');
$byName = [];
foreach ((array) ($probe['queries'] ?? []) as $query) {
    if (is_array($query)) {
        $byName[(string) ($query['name'] ?? '')] = $query;
    }
}
pp(!empty($byName['page.jarfalla']['ok']) && (int) (($byName['page.jarfalla']['value']['total'] ?? 0)) >= 1, 'page.jarfalla är sidans lista');
pp(!empty($byName['open.jarfalla']['ok']) && (int) ($byName['open.jarfalla']['value'] ?? 0) >= 1, 'open.jarfalla är kolumnen');
pp((int) ($byName['page.jarfalla']['value']['total'] ?? 0) >= 1 && (int) ($byName['open.jarfalla']['value'] ?? 0) >= 1, 'sidan och kolumnen är båda större än noll');

// Live-fallet: filflaggan säger json fast settings säger sql. Sidan läser då JSON, och proben ska säga det.
file_put_contents($tmp . '/privat/inkorg_storage.txt', "json\n");
unset($GLOBALS['_JEAB_DATA_TYPE_LISTS']);
$jsonProbe = jeab_inkorg_probe(['person' => 'BotenFlowy']);
pp((string) ($jsonProbe['storage']['mode'] ?? '') === 'json', 'filflaggan json ger JSON-vägen');
pp((string) ($jsonProbe['storage']['settings'] ?? '') === 'sql', 'settings säger fortfarande sql');
pp((string) ($jsonProbe['storage']['decided_by'] ?? '') === 'file', 'proben säger att filen bestämmer');
pp((string) ($jsonProbe['storage']['note'] ?? '') !== '', 'proben förklarar att sidan läser JSON');
file_put_contents($tmp . '/privat/inkorg_storage.txt', "sql\n");
pp(jeab_inkorg_storage_mode() === 'sql', 'tillbaka i sql');

echo "OK  sidväg\n";
