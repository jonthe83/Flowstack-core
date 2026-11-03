<?php

declare(strict_types=1);

/**
 * SQL-sidan ska ge samma id, i samma ordning, som PHP-filtret.
 * Personens LIMIT får inte tappa en träff som sorteras efter andra (B-024).
 * Ort-skanningen hoppar över ajax (B-023). De gamla raderingskonstanterna är borta (B-025).
 */

$tmp = sys_get_temp_dir() . '/jeab-rem-sql-page-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$jlHash = password_hash('SamLsqlpage', PASSWORD_DEFAULT);
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => bin2hex(random_bytes(16)),
    'hashes' => [
        ['email' => 'demo@example.test', 'hash' => $jlHash],
    ],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'passwords_file' => $tmp . '/passwords.json',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
$_GET = ['p' => 'reminders', 'who' => 'all', 'tag' => 'none', 'v' => 'today'];
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/src/bootstrap.php';
date_default_timezone_set('Europe/Stockholm');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$fail = 0;
function sqlp(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

function sqlp_ids(array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $ids[] = (int) ($row['id'] ?? 0);
        }
    }

    return $ids;
}

sqlp(!defined('JEAB_COMPLETED_REMINDERS_KEEP') && !defined('JEAB_COMPLETED_REMINDERS_PRUNE_OVER'), 'B-025 gamla raderingskonstanter är borta');
sqlp(defined('JEAB_REMINDER_PRUNE_INTERVAL') && jeab_prune_completed_reminders(jeab_store()) === 0, 'B-025 prune är av och intervallet finns kvar');

$prevTick = $GLOBALS['_JEAB_TICK'] ?? null;
$prevPage = $GLOBALS['_JEAB_PAGE_REQUEST'] ?? null;
$prevIntent = $_GET['intent'] ?? null;
unset($GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_PAGE_REQUEST']);
$_GET['intent'] = '';
sqlp(jeab_reminder_ort_skip_key_scan() === false, 'B-023 vanlig vy utan sidflagga skannar');
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
sqlp(jeab_reminder_ort_skip_key_scan() === true, 'B-023 HTML-sidan hoppar över');
unset($GLOBALS['_JEAB_PAGE_REQUEST']);
foreach (['sync', 'more', 'page', 'pulse'] as $intent) {
    $_GET['intent'] = $intent;
    sqlp(jeab_reminder_ort_skip_key_scan() === true, 'B-023 intent=' . $intent . ' hoppar över');
}
$GLOBALS['_JEAB_TICK'] = true;
$_GET['intent'] = 'sync';
sqlp(jeab_reminder_ort_skip_key_scan() === false, 'B-023 tick får skanna');
unset($GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_PAGE_REQUEST']);
if ($prevIntent === null) {
    unset($_GET['intent']);
} else {
    $_GET['intent'] = $prevIntent;
}
if ($prevTick !== null) {
    $GLOBALS['_JEAB_TICK'] = $prevTick;
}
if ($prevPage !== null) {
    $GLOBALS['_JEAB_PAGE_REQUEST'] = $prevPage;
}

$store = jeab_store();
sqlp($store instanceof JeabPdoStore, 'sqlite-lager');
$pdo = $store->pdo();
$now = jeab_now();
$tz = new DateTimeZone('Europe/Stockholm');
$today = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
$yesterday = (new DateTimeImmutable('today', $tz))->modify('-1 day')->format('Y-m-d');
$seq = 0;
$add = static function (array $data) use ($store, $now, &$seq): int {
    $seq++;
    $created = sprintf('2026-01-01 %02d:%02d:%02d', intdiv($seq, 3600) % 24, intdiv($seq, 60) % 60, $seq % 60);
    $row = $store->insertReminder($data + [
        'created_at' => $created,
        'updated_at' => $now,
    ]);

    return (int) ($row['id'] ?? 0);
};

$lateRoberto = [];
for ($i = 0; $i < 30; $i++) {
    $who = $i % 2 === 0 ? 'Roberto' : 'Elin';
    $lateRoberto[] = $add([
        'title' => 'Sen gata ' . $i,
        'customer_name' => 'Kund ' . $i,
        'company' => 'jarfalla',
        'assignees' => [$who],
        'due_at' => $yesterday . sprintf(' %02d:%02d:00', 8, $i % 60),
        'tags' => $i % 5 === 0 ? ['vunnen'] : [],
    ]);
}
$todayIds = [];
for ($i = 0; $i < 25; $i++) {
    $todayIds[] = $add([
        'title' => 'Idag gata ' . $i,
        'customer_name' => 'Idag ' . $i,
        'company' => 'jarfalla',
        'assignees' => [$i % 3 === 0 ? 'Niman' : 'Sam L'],
        'due_at' => $today . ' 00:00:' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
    ]);
}
$waitingIds = [];
for ($i = 0; $i < 8; $i++) {
    $waitingIds[] = $add([
        'title' => 'Senare gata ' . $i,
        'customer_name' => 'Vantar ' . $i,
        'company' => 'jarfalla',
        'assignees' => ['Elin'],
        'due_at' => '2099-05-01 10:00:' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
    ]);
}
$doneIds = [];
for ($i = 0; $i < 12; $i++) {
    $doneIds[] = $add([
        'title' => 'Klar gata ' . $i,
        'customer_name' => 'Klar ' . $i,
        'company' => 'jarfalla',
        'assignees' => ['Roberto'],
        'due_at' => '2026-04-01 09:00:00',
        'completed_at' => '2026-06-01 12:' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . ':00',
    ]);
}
$noDue = $add([
    'title' => 'Utan datum',
    'customer_name' => 'Uten',
    'company' => 'jarfalla',
    'assignees' => ['Elin'],
]);
$blankId = $add([
    'title' => 'Tomt bolag',
    'customer_name' => 'Blank',
    'company' => 'jarfalla',
    'assignees' => ['Roberto'],
    'due_at' => $today . ' 00:01:00',
]);
$malmoId = $add([
    'title' => 'Malmö gata',
    'customer_name' => 'Malmo',
    'company' => 'malmo',
    'assignees' => ['Sam W'],
    'due_at' => $today . ' 00:01:00',
]);
$aliasId = $add([
    'title' => 'Sam förnamn',
    'customer_name' => 'Alias',
    'company' => 'jarfalla',
    'assignees' => ['Elin'],
    'due_at' => $today . ' 00:02:00',
]);
$personId = $add([
    'title' => 'Sam id',
    'customer_name' => 'Personkolumn',
    'company' => 'jarfalla',
    'assignees' => ['Elin'],
    'due_at' => $today . ' 00:03:00',
]);
$wrongSam = $add([
    'title' => 'Sam W i Järfälla',
    'customer_name' => 'Fel Sam',
    'company' => 'jarfalla',
    'assignees' => ['Elin'],
    'due_at' => $today . ' 00:04:00',
]);
$pdo->prepare('UPDATE reminders SET company = ? WHERE id = ?')->execute(['', $blankId]);
$pdo->prepare('UPDATE reminders SET assignee = ?, assignees = ?, person = ? WHERE id = ?')->execute([
    'Sam',
    '["Sam"]',
    null,
    $aliasId,
]);
$pdo->prepare('UPDATE reminders SET assignee = NULL, assignees = NULL, person = ? WHERE id = ?')->execute([
    'SamL',
    $personId,
]);
$pdo->prepare('UPDATE reminders SET assignee = ?, assignees = ?, person = NULL WHERE id = ?')->execute([
    'Sam W',
    '["Sam W"]',
    $wrongSam,
]);

$elinEarly = [];
for ($i = 0; $i < 55; $i++) {
    $elinEarly[] = $add([
        'title' => 'Elin tidig ' . $i,
        'customer_name' => 'Elin ' . $i,
        'company' => 'jarfalla',
        'assignees' => ['Elin'],
        'due_at' => '2020-01-01 08:00:' . str_pad((string) ($i % 60), 2, '0', STR_PAD_LEFT),
        'created_at' => sprintf('2020-01-02 %02d:%02d:00', intdiv($i, 60), $i % 60),
    ]);
}
$robertoLate = $add([
    'title' => 'Roberto sen',
    'customer_name' => 'Roberto sen',
    'company' => 'jarfalla',
    'assignees' => ['Roberto'],
    'due_at' => '2024-01-01 08:00:00',
]);

$tagNone = ['mode' => 'none', 'tag' => null];
$tagVunnen = ['mode' => 'tag', 'tag' => 'vunnen'];
$tagAll = ['mode' => 'all', 'tag' => null];

$samePage = static function (string $company, ?string $who, string $view, array $tag, int $offset, int $limit, int $open = 0) use ($store): bool {
    $all = jeab_reminders_load_company($store, $company, '');
    $scoped = jeab_filter_reminders_scope($all, $company, $who);
    $filtered = jeab_filter_reminders($scoped, $view, $tag, '');
    $page = jeab_reminder_page($filtered, $view, '', $offset, $limit, $open);
    $phpIds = sqlp_ids($filtered);
    $sqlIds = jeab_reminders_sql_ids($store, $company, $who, $view, $tag);
    $sqlPage = jeab_reminders_sql_page_ids($store, $company, $who, $view, $tag, $offset, $limit === 0 ? JEAB_REMINDER_DONE_PAGE : max(JEAB_REMINDER_DONE_PAGE, min($limit, 1000)));
    if ($open > 0 && $offset === 0) {
        $at = array_search($open, $sqlIds, true);
        $wide = $limit === 0 ? JEAB_REMINDER_DONE_PAGE : max(JEAB_REMINDER_DONE_PAGE, min($limit, 1000));
        if ($at !== false) {
            $wide = max($wide, (int) $at + 1);
        }
        $sqlPage = jeab_reminders_sql_page_ids($store, $company, $who, $view, $tag, 0, $wide);
    }
    $pack = jeab_reminders_sql_pack($store, $company, $who, $view, $tag, $offset, $limit, $open);
    $packIds = sqlp_ids($pack['rows']);
    $expectPage = sqlp_ids($page['rows']);
    $slice = array_slice($sqlIds, $offset, count($sqlPage));

    return $sqlIds === $phpIds
        && $sqlPage === $expectPage
        && $packIds === $expectPage
        && $sqlPage === $slice
        && (int) $pack['total'] === count($phpIds)
        && $pack['next'] === $page['next'];
};

$cases = [
    ['jarfalla', null, 'today', $tagNone, 0, 0, 0],
    ['jarfalla', null, 'today', $tagNone, 50, 50, 0],
    ['jarfalla', null, 'all', $tagAll, 0, 0, 0],
    ['jarfalla', null, 'all', $tagNone, 0, 0, 0],
    ['jarfalla', null, 'done', $tagAll, 0, 0, 0],
    ['jarfalla', null, 'waiting', $tagAll, 0, 0, 0],
    ['jarfalla', null, 'scheduled', $tagAll, 0, 0, 0],
    ['jarfalla', null, 'today', $tagVunnen, 0, 0, 0],
    ['jarfalla', 'Roberto', 'today', $tagNone, 0, 0, 0],
    ['jarfalla', 'Sam', 'today', $tagAll, 0, 0, 0],
    ['jarfalla', 'Sam L', 'all', $tagAll, 0, 0, 0],
    ['jarfalla', 'SamL', 'today', $tagAll, 0, 0, 0],
    ['jarfalla', 'Zorp', 'today', $tagAll, 0, 0, 0],
    ['malmo', null, 'today', $tagAll, 0, 0, 0],
];
foreach ($cases as [$company, $who, $view, $tag, $offset, $limit, $open]) {
    $label = $company . ' ' . ($who ?? 'alla') . ' ' . $view . ' ' . $tag['mode'] . ' offset ' . $offset;
    sqlp($samePage($company, $who, $view, $tag, $offset, $limit, $open), 'samma id ' . $label);
}

$allRows = jeab_reminders_load_company($store, 'jarfalla', '');
$todayFiltered = jeab_filter_reminders(jeab_filter_reminders_scope($allRows, 'jarfalla', null), 'today', $tagAll, '');
$openRow = $todayFiltered[54] ?? null;
$openId = (int) ($openRow['id'] ?? 0);
$openAt = array_search($openId, sqlp_ids($todayFiltered), true);
sqlp($openId > 0 && $openAt !== false && (int) $openAt >= JEAB_REMINDER_DONE_PAGE, 'öppet kort ligger efter första sidan');
$openPage = jeab_reminders_sql_page_ids($store, 'jarfalla', null, 'today', $tagAll, 0, JEAB_REMINDER_DONE_PAGE);
$openPack = jeab_reminders_sql_pack($store, 'jarfalla', null, 'today', $tagAll, 0, 0, $openId);
$openPackIds = sqlp_ids($openPack['rows']);
sqlp($openPage === array_slice($openPackIds, 0, count($openPage)), 'första sidan är oförändrad när kortet är öppet');
sqlp(count($openPage) === JEAB_REMINDER_DONE_PAGE && count($openPackIds) === JEAB_REMINDER_DONE_PAGE + 1, 'sidan vidgas inte till kortets plats');
sqlp($openPackIds[count($openPackIds) - 1] === $openId, 'öppet kort hämtas separat och läggs sist');
sqlp(!empty($openPack['rows'][count($openPack['rows']) - 1]['_jeab_open_extra']), 'det extra kortet markeras så synken inte räknar det som en sida');
sqlp((int) $openPack['total'] === count($todayFiltered) && $openPack['next'] === JEAB_REMINDER_DONE_PAGE, 'total och nästa sida följer 50, inte kortets plats');
$firstOpen = (int) ($todayFiltered[0]['id'] ?? 0);
$firstPack = jeab_reminders_sql_pack($store, 'jarfalla', null, 'today', $tagAll, 0, 0, $firstOpen);
sqlp($firstOpen > 0 && sqlp_ids($firstPack['rows']) === $openPage, 'öppet kort på sidan dupliceras inte');

$phpCounts = jeab_reminder_counts(jeab_filter_reminders_scope($allRows, 'jarfalla', null), $tagNone, '');
$sqlCounts = jeab_reminders_sql_counts($store, 'jarfalla', null, $tagNone);
sqlp($phpCounts === $sqlCounts, 'flikarnas siffror är lika');
$phpWho = jeab_reminder_who_counts($allRows, 'jarfalla', 'today', $tagAll, '');
$sqlWho = jeab_reminders_sql_who_counts($store, 'jarfalla', 'today', $tagAll);
sqlp(($sqlWho['all'] ?? -1) === ($phpWho['all'] ?? -2), 'Alla-räknaren är lika');
sqlp(array_keys($sqlWho) === ['all'], 'utan inloggning räknas bara Alla');
sqlp(($sqlWho['all'] ?? -1) === count($todayFiltered), 'alla är hela idag-listan');

$jon = jeab_filter_reminders(jeab_filter_reminders_scope($allRows, 'jarfalla', 'Sam L'), 'today', $tagAll, '');
$jonIds = sqlp_ids($jon);
sqlp(in_array($aliasId, $jonIds, true) && in_array($personId, $jonIds, true), 'Sam träffar förnamn och person-id');
sqlp(!in_array($wrongSam, $jonIds, true) && !in_array($malmoId, $jonIds, true), 'Sam W och Malmö ingår inte');
sqlp(in_array($blankId, sqlp_ids($todayFiltered), true), 'tomt bolag räknas som Järfälla');
sqlp(!in_array($malmoId, sqlp_ids($todayFiltered), true), 'Malmö är utanför Järfälla');

$limited = jeab_list_reminders($store, true, 'jarfalla', 'Roberto', 50);
$limitedIds = sqlp_ids($limited);
sqlp(in_array($robertoLate, $limitedIds, true) && count($limitedIds) <= 50, 'B-024 Roberto finns kvar när LIMIT är 50');
foreach ($limited as $row) {
    if (!is_array($row) || !jeab_reminder_matches_who($row, 'Roberto')) {
        sqlp(false, 'B-024 en rad i Robertos sida matchar inte');
        break;
    }
}
sqlp(jeab_list_reminders($store, true, 'jarfalla', 'Zorp', 50) === [], 'okänd person ger ingen rad');

$pulseRows = [
    ['id' => 4, 'updated_at' => 'a', 'completed_at' => '', 'due_at' => 'b', 'flagged' => 0, 'tags' => ['vunnen'], 'note' => 'hemlig'],
];
$pulse = jeab_reminder_pulse_payload($pulseRows, ['today' => 2, 'done' => 1], 9, 50);
sqlp(($pulse['ok'] ?? false) === true && !array_key_exists('items', $pulse) && ($pulse['stamp'] ?? '') === jeab_reminder_list_stamp($pulseRows), 'puls har stämpel och inga kort');
sqlp((int) ($pulse['total'] ?? 0) === 9 && (int) ($pulse['counts']['today'] ?? 0) === 2, 'puls bär siffror');

$index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
sqlp(str_contains($index, "\$search === '' && \$store instanceof JeabPdoStore"), 'sök använder inte SQL-sidan');
sqlp(str_contains($index, 'jeab_reminders_load_company'), 'sökvägen laddar fortfarande bolagets lista');
sqlp(str_contains($index, "jeab_reminders_ensure_ort(\$store, jeab_reminders_fill_bodies(\$store, \$page['rows']), false)"), 'anteckning och ort fylls efter sidan');
$pulseAt = strpos($index, "if (\$intent === 'pulse')");
$visibleFill = strpos($index, '$visible = jeab_reminders_ensure_ort');
$syncCheap = strpos($index, 'jeab_reminder_sync_stamp_matches');
sqlp($pulseAt !== false && $visibleFill !== false && $pulseAt < $visibleFill, 'pulsen svarar innan anteckningarna läses');
sqlp($syncCheap !== false && $visibleFill !== false && $syncCheap < $visibleFill, 'oförändrad synk svarar innan korten ritas');
sqlp(str_contains($index, "(string) (\$_POST['drop_related'] ?? '') === '1'"), 'ordern tas bort bara när drop_related är 1');
$stampRows = [
    ['id' => 4, 'updated_at' => 'a', 'completed_at' => '', 'due_at' => 'b', 'flagged' => 0, 'tags' => ['vunnen'], 'note' => 'hemlig'],
];
$stamp = jeab_reminder_list_stamp($stampRows);
sqlp(jeab_reminder_sync_stamp_matches($stamp, $stampRows) === true, 'samma stämpel hoppar över kort-HTML');
sqlp(jeab_reminder_sync_stamp_matches('', $stampRows) === false && jeab_reminder_sync_stamp_matches('kort', $stampRows) === false, 'tom eller avvikande stämpel ritar korten');

$app = (string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
sqlp(str_contains($app, 'function pulseReminders(') && str_contains($app, 'function askVunnenDrop('), 'klienten pulsar och frågar innan #vunnen tas bort');
sqlp(str_contains($app, 'softSyncReminders(\'visible\')') && str_contains($app, 'softSyncReminders(\'pageshow\')'), 'synlig flik gör fortfarande en hel synk');
sqlp(str_contains($app, "url.searchParams.set('stamp', reminderSoftStamp)") && str_contains($app, '!Array.isArray(data.items)'), 'synken skickar stämpeln och ritar inte om när den är lika');
sqlp(str_contains($app, ".item:not([data-open-extra])"), 'synken räknar sidan utan det extra öppna kortet');
$syncFnAt = strpos($app, 'function softSyncReminders(');
$syncFnEnd = strpos($app, "\n  function ", $syncFnAt === false ? 0 : $syncFnAt + 10);
$syncFn = ($syncFnAt === false || $syncFnEnd === false) ? '' : substr($app, $syncFnAt, $syncFnEnd - $syncFnAt);
sqlp($syncFn !== '' && !str_contains($syncFn, "searchParams.delete('open')"), 'synken behåller open så kortet utanför sidan stannar');

$sqlitePath = (string) (jeab_config()['db']['sqlite_path'] ?? '');
$counter = new class($sqlitePath) extends PDO {
    public int $counts = 0;

    public int $tallies = 0;

    public int $prepares = 0;

    public function __construct(string $path)
    {
        parent::__construct('sqlite:' . $path);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepares++;
        if (preg_match('/\bCOUNT\(\*\)/i', $query) === 1) {
            $this->counts++;
        }
        if (preg_match('/\bSUM\s*\(\s*CASE\b/i', $query) === 1) {
            $this->tallies++;
        }

        return parent::prepare($query, $options);
    }
};
$store->adoptPdo($counter);
jeab_reminders_count_cache_forget($counter);
$counter->counts = 0;
$counter->tallies = 0;
$counter->prepares = 0;
$packed = jeab_reminders_sql_pack($store, 'jarfalla', null, 'today', $tagNone, 0, 0, 0, true);
sqlp($counter->counts === 0, 'sidan gör inga separata COUNT(*), fick ' . $counter->counts);
sqlp($counter->tallies === 1, 'flikar och Alla räknas i en fråga, fick ' . $counter->tallies);
sqlp($counter->prepares === 3, 'sidladdningen förbereder tre frågor, fick ' . $counter->prepares);
$phpWhoNone = jeab_reminder_who_counts($allRows, 'jarfalla', 'today', $tagNone, '');
sqlp($packed['counts'] === $phpCounts, 'de sparade fliktalen är oförändrade');
sqlp((int) ($packed['who']['all'] ?? -1) === (int) ($phpWhoNone['all'] ?? -2), 'paketets Alla är oförändrad');
$filled = jeab_reminders_fill_bodies($store, $packed['rows']);
sqlp(jeab_reminder_list_stamp($filled) === jeab_reminder_list_stamp($packed['rows']), 'liststämpeln är samma före och efter anteckningen');
$counter->tallies = 0;
$counter->prepares = 0;
$cached = jeab_reminders_sql_pack($store, 'jarfalla', null, 'today', $tagNone, 0, 0, 0, true);
sqlp($counter->tallies === 0 && $cached['counts'] === $packed['counts'] && sqlp_ids($cached['rows']) === sqlp_ids($packed['rows']), 'nästa sidladdning återanvänder räknarna');
$GLOBALS['_JEAB_REMINDER_COUNT_TTL'] = 0;
$counter->tallies = 0;
$expired = jeab_reminders_sql_pack($store, 'jarfalla', null, 'today', $tagNone, 0, 0, 0, true);
unset($GLOBALS['_JEAB_REMINDER_COUNT_TTL']);
sqlp($counter->tallies === 1 && $expired['counts'] === $packed['counts'], 'utgången cache räknar om till samma tal');
$jlEmail = '';
foreach (jeab_people() as $person) {
    if (($person['id'] ?? '') === 'SamL') {
        $jlEmail = (string) ($person['email'] ?? '');
    }
}
$_SESSION['jeab_email'] = $jlEmail;
jeab_code_people_save(jeab_auth_hash_fingerprint($jlHash), 'SamL', 'prefix', 'SamL');
$_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value($jlEmail, $jlHash);
jeab_reminders_count_cache_forget($counter);
$counter->tallies = 0;
$counter->counts = 0;
$minaWho = jeab_reminders_sql_who_counts($store, 'jarfalla', 'today', $tagAll);
$minaQueries = $counter->tallies;
unset($_SESSION['jeab_email']);
sqlp($jlEmail !== '' && array_keys($minaWho) === ['all', 'Sam L'], 'inloggad Sam L räknas bara som Alla och Mina');
sqlp($minaQueries === 1 && $counter->counts === 0, 'Mina och Alla är en fråga, fick ' . $minaQueries);
sqlp((int) ($minaWho['Sam L'] ?? -1) === (int) ($phpWho['Sam L'] ?? -2), 'Minas tal är samma som personfiltret');
$doneBefore = (int) ($packed['counts']['done'] ?? 0);
$todayBefore = (int) ($packed['counts']['today'] ?? 0);
jeab_reminders_sql_pack($store, 'jarfalla', null, 'today', $tagNone, 0, 0, 0, true);
$markId = (int) ($packed['rows'][0]['id'] ?? 0);
$counter->tallies = 0;
$store->saveReminder($markId, ['completed_at' => jeab_now(), 'updated_at' => jeab_now()]);
$afterWrite = jeab_reminders_sql_pack($store, 'jarfalla', null, 'today', $tagNone, 0, 0, 0, true);
sqlp($markId > 0 && $counter->tallies === 1, 'ändrad påminnelse tömmer räknarcachen');
sqlp((int) $afterWrite['counts']['done'] === $doneBefore + 1 && (int) $afterWrite['counts']['today'] === $todayBefore - 1, 'klar påminnelse flyttar talen idag och klara');

echo $fail === 0 ? "\nall passed\n" : "\n{$fail} failed\n";
exit($fail === 0 ? 0 : 1);
