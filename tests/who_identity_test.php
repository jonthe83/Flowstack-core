<?php

declare(strict_types=1);

/**
 * Förvalet kommer bara från inloggningen. Gamla kakor, sparade personval och
 * who-picks ändrar inte listan. Ingen person betyder Alla, utan fråga.
 * Gamla adresser med who=all, engångsrensning och diagnosloggen ligger kvar.
 */

$tmp = sys_get_temp_dir() . '/jeab-who-id-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
$hashLabeled = password_hash('kod-med-epost', PASSWORD_DEFAULT);
$hashAnon = password_hash('kod-utan-epost', PASSWORD_DEFAULT);
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => bin2hex(random_bytes(16)),
    'hashes' => [['email' => 'agenter@flowstack.example.test', 'hash' => $hashLabeled], ['hash' => $hashAnon, 'note' => 'resande']],
]));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'passwords_file' => $tmp . '/passwords.json',
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';
jeab_auth_data(true);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function wi_assert(bool $ok, string $m): void
{
    if ($ok) {
        echo "OK  {$m}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$m}\n");
    exit(1);
}

/** Ny enhet/sidladdning: bara kakan, tom session. */
function wi_fresh(?string $cookie, array $q): void
{
    $_GET = $q;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SESSION = [];
    $_COOKIE[JEAB_AUTH_COOKIE] = $cookie;
    unset($GLOBALS['_JEAB_WHO_DIAG'], $GLOBALS['_JEAB_WHO_MIGRATED']);
    jeab_who_migrate();
}

/** Samma enhet, ny sidladdning. */
function wi_next(array $q): void
{
    $_GET = $q;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($GLOBALS['_JEAB_WHO_DIAG']);
    jeab_who_migrate();
}

function wi_v1(): string
{
    $payload = '1.' . time() . '.' . bin2hex(random_bytes(8));
    return $payload . '.' . hash_hmac('sha256', $payload, jeab_auth_secret());
}

function wi_page(): string
{
    $view = 'all';
    $counts = ['today' => 0, 'scheduled' => 0, 'all' => 0, 'waiting' => 0, 'done' => 0];
    $visible = [];
    $search = '';
    return require dirname(__DIR__) . '/src/pages/reminders.php';
}

$menu = ['p' => 'reminders', 'co' => 'jarfalla'];

// ---- v1-kaka (före 4 okt): ingen kod, ingen e-post ------------------------------------------
$v1 = wi_v1();
wi_fresh($v1, $menu);
wi_assert(jeab_auth_value_ok($v1), 'v1-kakan släpps fortfarande in');
wi_assert(jeab_who_cookie_info()['ver'] === '1' && jeab_who_cookie_info()['fp'] === null, 'v1: inget fingeravtryck');
wi_assert(jeab_who_me_person() === null && jeab_reminder_who_filter() === null, 'v1 utan person: Alla');
$html = wi_page();
wi_assert(!str_contains($html, 'Välj vem du är') && !str_contains($html, 'who-pick') && !str_contains($html, 'who.pick') && !str_contains($html, 'Ingen person vald'), 'Påminnelser frågar inte vem du är');
jeab_who_pick_save(jeab_who_cookie_info()['key'], 'SamL');
wi_fresh($v1, $menu);
wi_assert(jeab_reminder_who_filter() === null && jeab_who_me_person() === null && jeab_who_me_source() === 'none', 'sparat val på v1 ändrar inte listan');
wi_assert(jeab_filter_from_request('jarfalla', true)['who'] === null, 'Ordrar följer inte det sparade valet');
wi_assert(jeab_auth_user_person() === null, 'inloggningen är fortfarande ingen person');

// ---- v3 utan e-post: inget förnamn, inget val som styr listan ------------------------------
$anon = jeab_auth_issue_value(null, $hashAnon);
wi_fresh($anon, $menu);
wi_assert(jeab_who_me_person() === null && jeab_reminder_who_filter() === null, 'kod utan e-post och utan förnamn: Alla');
jeab_who_pick_save(jeab_who_cookie_info()['key'], 'SamL');
wi_fresh(jeab_auth_issue_value(null, $hashAnon), $menu);
wi_assert(jeab_who_me_person() === null && jeab_reminder_who_filter() === null && jeab_who_me_source() === 'none', 'samma kod på annan enhet: fortfarande Alla');
$codes = jeab_who_codes();
wi_assert(count($codes) === 2 && $codes[1]['person'] === 'Sam L' && $codes[1]['via'] === 'eget val', 'kodlistan: fp -> Sam L via eget val');
wi_assert(str_contains($codes[1]['label'], 'note=resande') && $codes[0]['person'] === null && $codes[0]['via'] === '', 'kodlistan: delad agentkod är ingen person');
$dump = json_encode($codes);
wi_assert(!str_contains((string) $dump, '$2y$') && !str_contains((string) $dump, substr($hashAnon, 7, 20)), 'kodlistan visar aldrig hash');
wi_assert(preg_match('/^[0-9a-f]{16}$/', $codes[1]['fp']) === 1, 'fp är 16 hex');

// ---- «Ingen av dem» = Alla utan att fråga igen ---------------------------------------------
$v1b = wi_v1();
wi_fresh($v1b, $menu);
jeab_who_pick_save(jeab_who_cookie_info()['key'], 'none');
wi_fresh($v1b, $menu);
wi_assert(jeab_who_me_person() === null && jeab_reminder_who_filter() === null && jeab_who_scope_note_html('jarfalla') === '', 'sparat «ingen» är fortfarande bara Alla, utan notis');

// ---- Gamla adresser med who=all -------------------------------------------------------------
$lab = jeab_auth_issue_value('agenter@flowstack.example.test', $hashLabeled);
wi_fresh($lab, ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'today', 'tag' => 'none', 'who' => 'all']);
wi_assert(jeab_reminder_who_filter() === null && jeab_is_admin(), 'gammal flik/bokmärke med who=all: Alla, agentkoden är admin');
wi_fresh($lab, ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'today', 'who' => 'all', 'ws' => (string) (time() - 4000)]);
wi_assert(jeab_reminder_who_filter() === null, 'who=all med gammal ws: Alla');
wi_fresh($lab, ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'today', 'who' => 'all', 'ws' => (string) time()]);
wi_assert(jeab_reminder_who_filter() === null, 'nyss klickat Alla: Alla');
wi_next(['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'all', 'who' => 'all', 'intent' => 'page']);
wi_assert(jeab_reminder_who_filter() === null, 'fortsatt arbete (session) utan ny ws: Alla');
$u = jeab_reminders_url(['v' => 'done', 'open' => null]);
wi_assert(str_contains($u, 'who=all') && preg_match('/ws=\d+/', $u) === 1, 'genererade länkar bär ws');
wi_fresh($lab, ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'today', 'who' => 'Sam L']);
wi_assert(jeab_reminder_who_filter() === null && jeab_auth_user_person() === null, 'bokmärke med Sam L utan ws gör inte agentkoden till honom');
wi_fresh($lab, ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'today', 'who' => 'Sam L', 'ws' => (string) time()]);
wi_assert(jeab_reminder_who_filter() === 'Sam L' && jeab_auth_user_person() === null && jeab_is_admin(), 'färskt val av Sam L är bara filtret; agentkoden är admin utan att bli personen');

// Ordrar
wi_fresh($lab, ['p' => 'ordrar', 'co' => 'jarfalla', 'who' => 'all', 'job' => 'all']);
wi_assert(jeab_filter_from_request('jarfalla', true)['who'] === null, 'Ordrar gammal adress who=all: Alla');
wi_fresh($lab, ['p' => 'ordrar', 'co' => 'jarfalla', 'who' => 'all', 'job' => 'all', 'ws' => (string) time()]);
wi_assert(jeab_filter_from_request('jarfalla', true)['who'] === null, 'Ordrar nyss klickat Alla: Alla');
wi_next(['p' => 'ordrar', 'co' => 'jarfalla', 'who' => 'all', 'job' => 'all']);
wi_assert(jeab_filter_from_request('jarfalla', true)['who'] === null, 'Ordrar samma val i sessionen: Alla kvar');
wi_assert(str_contains(jeab_ordrar_url(['co' => 'jarfalla', 'who' => 'all', 'job' => 'all']), 'ws='), 'Ordrar-länkar bär ws');
wi_assert(!str_contains(jeab_ordrar_url(['co' => 'jarfalla']), 'ws='), 'Ordrar-länk utan filter: ingen ws');
wi_fresh($lab, ['p' => 'bookings', 'co' => 'jarfalla', 'who' => 'all', 'job' => 'all']);
wi_assert(jeab_filter_from_request('jarfalla')['explicit'] === true, 'Kalendern oförändrad (who=all gäller)');

// ---- Engångsrensning av sparade val ---------------------------------------------------------
$_COOKIE[JEAB_AUTH_COOKIE] = $lab;
$_SESSION = ['jeab_reminder_who' => ['jarfalla' => ['who' => 'all', 'at' => time()]], 'jeab_ordrar_filter' => ['jarfalla' => ['sig' => 'all|all', 'at' => time()]], 'jeab_who_ver' => 2];
wi_next(['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'today', 'open' => '5']);
wi_assert(!isset($_SESSION['jeab_reminder_who']) && !isset($_SESSION['jeab_ordrar_filter']) && $_SESSION['jeab_who_ver'] === JEAB_WHO_VER, 'gammal session: sparade val rensade en gång');
wi_assert(jeab_reminder_who_filter() === null && jeab_is_admin(), 'efter rensning: Alla, agentkoden är admin');

// ---- Diagnos --------------------------------------------------------------------------------
@unlink(jeab_who_diag_path());
wi_fresh($v1, $menu);
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1';
jeab_reminder_who_filter();
jeab_who_diag_record();
$lines = file(jeab_who_diag_path(), FILE_IGNORE_NEW_LINES);
$row = json_decode((string) end($lines), true);
wi_assert(is_array($row) && $row['ver'] === '1' && ($row['me'] ?? null) === null && $row['me_src'] === 'none' && $row['who'] === 'Alla' && $row['ua'] === 'iOS/Safari', 'diagnosrad: v1 är ingen person, Alla, enhet');
wi_assert(str_starts_with((string) $row['key'], 'c1:') && !str_contains((string) end($lines), $v1), 'diagnos: aldrig kakan själv');
wi_fresh($lab, ['p' => 'ordrar', 'co' => 'jarfalla', 'who' => 'all', 'job' => 'all']);
jeab_filter_from_request('jarfalla', true);
jeab_who_diag_record();
$lines = file(jeab_who_diag_path(), FILE_IGNORE_NEW_LINES);
$row = json_decode((string) end($lines), true);
wi_assert($row['page'] === 'ordrar' && $row['who'] === 'Alla' && ($row['me'] ?? null) === null && str_starts_with((string) $row['who_src'], 'url-stale-ignored') && preg_match('/^[0-9a-f]{16}$/', (string) $row['fp']) === 1, 'diagnosrad Ordrar: gammal adress ignorerad, agentkoden är inte Sam L');
$picks = (string) file_get_contents(jeab_who_picks_path());
wi_assert(!str_contains($picks, '$2y$'), 'sparade val innehåller ingen hash');


// ---- Ingen ruta, varken på Påminnelser, Ordrar eller Kalender --------------------------------
$v1b = wi_v1();
wi_fresh($v1b, $menu);
$html = wi_page();
wi_assert(!str_contains($html, 'who-pick-modal') && !str_contains($html, 'Välj vem du är'), 'Påminnelser har ingen ruta');
$calSrc = (string) file_get_contents(dirname(__DIR__) . '/src/pages/bookings.php');
$ordSrc = (string) file_get_contents(dirname(__DIR__) . '/src/pages/ordrar.php');
$remSrc = (string) file_get_contents(dirname(__DIR__) . '/src/pages/reminders.php');
wi_assert(!str_contains($calSrc, 'jeab_who_picker_html') && !str_contains($calSrc, 'Välj vem du är'), 'Kalendern frågar inte');
wi_assert(!str_contains($ordSrc, 'jeab_who_picker_html') && !str_contains($ordSrc, 'Välj vem du är'), 'Ordrar frågar inte');
wi_assert(!str_contains($remSrc, 'jeab_who_picker_html') && !str_contains($remSrc, 'Välj vem du är'), 'Påminnelser-källan frågar inte');
jeab_who_pick_save(jeab_who_cookie_info()['key'], 'SamL');
wi_fresh($v1b, $menu);
wi_assert(!str_contains(wi_page(), 'who-pick-modal') && jeab_who_me_source() === 'none', 'sparat val ger ingen ruta och ingen person');
wi_assert(jeab_filter_login_preset('jarfalla')['who'] === null, 'Kalenderns förval utan person är Alla');

echo "ALLA OK\n";
