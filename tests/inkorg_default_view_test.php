<?php

declare(strict_types=1);

/** Startvy i Inkorgen: sparad ägarvy, annars Öppna, annars Osorterat. Historik bara via länk. */

$tmp = sys_get_temp_dir() . '/jeab-inkdef-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
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

function dv(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function dv_title(string $html): string
{
    return preg_match('/<h2 class="crm-section-title">([^<]*)<\/h2>/', $html, $m) === 1 ? html_entity_decode($m[1]) : '';
}

function dv_on(string $html): string
{
    if (preg_match('/<a class="smart-tile is-on"[^>]*>.*?<span>([^<]*)<\/span>/s', $html, $m) !== 1) {
        return '';
    }

    return html_entity_decode($m[1]);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla'];
unset($_SESSION['jeab_email'], $_COOKIE['jeab_auth']);

$views = jeab_inkorg_views();
$ids = array_column($views, 'id');
dv(in_array('v-historik', $ids, true) && ($ids[0] ?? '') !== 'v-historik', 'Historik finns och ligger inte först i startdatan');

$front = $views;
$hist = null;
$rest = [];
foreach ($front as $v) {
    if (($v['id'] ?? '') === 'v-historik') {
        $hist = $v;
    } else {
        $rest[] = $v;
    }
}
dv(is_array($hist), 'Historik går att lyfta');
$front = array_merge([$hist], $rest);
dv(jeab_inkorg_default_view($front, '') === 'v-oppna', 'utan identitet är Öppna även när Historik ligger först');
dv(jeab_inkorg_default_view($front, 'Roberto') === 'v-batteri-oppna', 'sparad ägarvy vinner över Historik först');
dv(jeab_inkorg_default_view($front, 'Niman') === 'v-oppna', 'inloggad utan egen vy får Öppna, inte menyns första kort');
dv(jeab_inkorg_requested_view('', $front, 'Elin') === 'v-inkorg', 'tom förfrågan öppnar Inkorg');
dv(jeab_inkorg_default_view($front, 'Elin') === 'v-elbil-osorterat', 'sparad ägarvy finns kvar för den som frågar efter den');
dv(jeab_inkorg_requested_view('v-historik', $front, '') === 'v-historik', 'explicit Historik-länk öppnar Historik');
dv(jeab_inkorg_requested_view('  v-historik  ', $front, '') === 'v-historik', 'Historik-länk med blanksteg öppnar Historik');
dv(jeab_inkorg_requested_view('v-solceller', $front, 'Niman') === 'v-solceller', 'explicit sparad vy öppnas med sitt id');
dv(jeab_inkorg_requested_view('v-papperskorg', $front, '') === 'v-inkorg', 'pensionerad vy öppnar Inkorg');
dv(jeab_inkorg_requested_view('saknas', $front, 'SamL') === 'v-inkorg', 'okänd vy öppnar Inkorg, inte sparad vy');
dv(jeab_inkorg_requested_view('historik', $front, 'SamL') === 'v-inkorg', 'view=historik är inte Historik');
dv(jeab_inkorg_requested_view('nope', $front, 'SamL') === 'v-inkorg', 'view=nope öppnar Inkorg');

$noOppna = array_values(array_filter($front, fn($v) => ($v['id'] ?? '') !== 'v-oppna'));
dv(jeab_inkorg_default_view($noOppna, '') === 'v-osorterat', 'saknas Öppna blir det Osorterat');
$noOpen = array_values(array_filter($noOppna, fn($v) => !in_array($v['id'] ?? '', ['v-osorterat', 'v-allt'], true)));
dv(jeab_inkorg_default_view($noOpen, '') !== 'v-historik' && jeab_inkorg_default_view($noOpen, '') !== '', 'utan Öppna och Osorterat väljs ändå inte Historik');

$migrated = jeab_inkorg_views_migrate(['dry' => 0]);
dv((int) ($migrated['dry'] ?? 1) === 0, 'views_migrate körs skarpt i testkatalogen');
$after = jeab_inkorg_views();
$hist = null;
$rest = [];
foreach ($after as $v) {
    if (($v['id'] ?? '') === 'v-historik') {
        $hist = $v;
    } else {
        $rest[] = $v;
    }
}
jeab_inkorg_views_replace(array_merge([$hist], $rest));
$stored = jeab_inkorg_views();
dv(($stored[0]['id'] ?? '') === 'v-historik', 'efter migrering kan Historik ligga först i sparad ordning');
dv(jeab_inkorg_default_view($stored, '') === 'v-oppna', 'migreringens ordning gör inte Historik till standard');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla'];
$html = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
dv(dv_title($html) === 'Inkorg' && dv_on($html) === 'Inkorg', 'första laddningen visar Inkorg: ' . dv_title($html) . ' / ' . dv_on($html));
dv(str_contains($html, '>Historik</span>'), 'Historik finns kvar i menyn');
dv(!str_contains($html, 'smart-tile is-on">') || dv_on($html) !== 'Historik', 'Historik är inte markerad vid start');

$_SESSION['jeab_email'] = 'saljare3@jarfalla.example.test';
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla'];
$html = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
dv(dv_title($html) === 'Inkorg' && dv_on($html) === 'Inkorg', 'efter inloggning visas Inkorg: ' . dv_title($html) . ' / ' . dv_on($html));

unset($_SESSION['jeab_email']);
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-historik'];
$html = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
dv(dv_title($html) === 'Historik' && dv_on($html) === 'Historik', 'view=v-historik öppnar Historik');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'vy' => 'v-historik'];
$html = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
dv(dv_title($html) === 'Historik', 'vy=v-historik öppnar Historik');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'historik'];
$html = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
dv(dv_title($html) === 'Inkorg' && dv_on($html) === 'Inkorg', 'view=historik visar Inkorg: ' . dv_title($html) . ' / ' . dv_on($html));

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'nope'];
$html = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
dv(dv_title($html) === 'Inkorg' && dv_on($html) === 'Inkorg', 'view=nope visar Inkorg: ' . dv_title($html) . ' / ' . dv_on($html));

$allaFirst = [
    ['id' => 'v-allt', 'name' => 'Alla', 'filter' => []],
    ['id' => 'v-historik', 'name' => 'Historik', 'filter' => ['historik' => true]],
    ['id' => 'v-oppna', 'name' => 'Öppna', 'filter' => ['status' => ['open']]],
];
dv(jeab_inkorg_default_view($allaFirst, '') === 'v-oppna', 'Alla och Historik före Öppna blir ändå inte startvy');
dv(jeab_inkorg_default_view($allaFirst, 'SamL') === 'v-oppna', 'inloggad utan egen vy hoppar över Alla');
$ownedAlla = [
    ['id' => 'v-allt', 'name' => 'Alla', 'owner' => 'Roberto', 'filter' => []],
    ['id' => 'v-oppna', 'name' => 'Öppna', 'filter' => ['status' => ['open']]],
];
dv(jeab_inkorg_default_view($ownedAlla, 'Roberto') === 'v-oppna', 'en ägd Alla-vy är inte startvy');
dv(jeab_inkorg_default_view(array_slice($allaFirst, 0, 2), '') === '', 'bara Alla och Historik ger ingen startvy');

$tid = 'hsi-' . substr(hash('sha256', 'unknown-view-cap'), 0, 16);
$conv = [
    'id' => $tid,
    'company' => 'jarfalla',
    'status' => 'open',
    'import_source' => 'helpscout_import',
    'messages' => [[
        'id' => substr(hash('sha256', 'unknown-view-msg'), 0, 16),
        'from' => 'kund.okand@exempel.test',
        'from_name' => 'Kund Okand',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Osaker import',
        'body' => 'Hej',
        'received_at' => '2026-04-01T10:00:00+00:00',
        'source' => 'helpscout_import',
        'company' => 'jarfalla',
        'labels' => ['type' => 'Support', 'action' => 'arkivera', 'priority' => 4],
        'internal_note' => false,
        'attachments' => [],
    ]],
    'meta' => [
        'status' => 'open',
        'tags' => [],
        'assignee' => '',
        'read' => [],
        'import_source' => 'helpscout_import',
        'updated_at' => '2026-10-18T12:00:00+00:00',
        'created_at' => '2026-10-18T12:00:00+00:00',
    ],
];
dv(jeab_hs_import_save_conv($conv) && jeab_hs_import_index_append(jeab_hs_import_index_row($conv)), 'osäker import skrivs');
$stored = jeab_hs_import_index()['by_id'][$tid] ?? null;
dv(is_array($stored), 'osäker import finns i indexet');
$uncertain = jeab_hs_import_row_thread($stored);
$GLOBALS['_JEAB_INKORG_HS_OPEN_CAP_CALLS'] = 0;
jeab_inkorg_thread_status($uncertain);
dv((int) $GLOBALS['_JEAB_INKORG_HS_OPEN_CAP_CALLS'] >= 1, 'samma import träffar öppna-taket via det gamla statusfiltret');

$prepare = static function (string $view): array {
    $_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => $view];
    unset($GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP']);
    $GLOBALS['_JEAB_INKORG_HS_OPEN_CAP_CALLS'] = 0;

    return jeab_inkorg_fast_prepare(true);
};

$asInkorg = $prepare('historik');
dv((string) ($asInkorg['vyId'] ?? '') === 'v-inkorg', 'view=historik förbereds som v-inkorg');
dv((string) (($asInkorg['view']['filter']['folder'] ?? '')) === 'inkorg', 'view=historik använder Inkorg-mappen');
dv((int) $GLOBALS['_JEAB_INKORG_HS_OPEN_CAP_CALLS'] === 0, 'view=historik anropar inte jeab_inkorg_hs_open_cap');

$asNope = $prepare('nope');
dv((string) ($asNope['vyId'] ?? '') === 'v-inkorg', 'view=nope förbereds som v-inkorg');
dv((string) (($asNope['view']['filter']['folder'] ?? '')) === 'inkorg', 'view=nope använder Inkorg-mappen');
dv((int) $GLOBALS['_JEAB_INKORG_HS_OPEN_CAP_CALLS'] === 0, 'view=nope anropar inte jeab_inkorg_hs_open_cap');

$asHist = $prepare('v-historik');
dv((string) ($asHist['vyId'] ?? '') === 'v-historik', 'view=v-historik förbereds som Historik');
dv((string) ($asHist['view']['name'] ?? '') === 'Historik', 'view=v-historik heter Historik');
dv((string) (($asHist['view']['filter']['folder'] ?? '')) === 'historik', 'view=v-historik använder Historik-mappen');

echo "default view ok\n";
