<?php

declare(strict_types=1);

/**
 * Påminnelser-polish: «Försenat» före «Idag», chips (ansvarig + produkt) på stängd rad,
 * flikarnas siffror följer taggfiltret, sök hoppar inte till Alla, personval följer med
 * mellan Malmö/Järfälla, Klara utan knuff/kontakt, ring-knapp på raden, Klar med done=1.
 * Påhittad testdata, inga riktiga kunder.
 */

$tmp = sys_get_temp_dir() . '/jeab-rem-polish-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function pp_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function pp_req(array $query): void
{
    $_GET = $query;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SESSION['jeab_email'] = 'agenter@flowstack.example.test';
}

$tz = new DateTimeZone(date_default_timezone_get());
$today = new DateTimeImmutable('today', $tz);
$at = static fn (string $mod, string $time = '09:00:00'): string => $today->modify($mod)->format('Y-m-d') . ' ' . $time;
$now = jeab_now();

$rows = [
    ['id' => 1, 'title' => 'Testgatan 1', 'customer_name' => 'Test Ett', 'due_at' => $at('-9 days'), 'note' => 'Från Help Scout #1 batteri fritext → UTKAST | ev. elbilsladdare-intresse', 'person' => 'Roberto', 'company' => 'jarfalla', 'phone' => '0701234567', 'created_at' => $now, 'updated_at' => $now],
    ['id' => 2, 'title' => 'Testgatan 2', 'customer_name' => 'Test Två', 'due_at' => $at('+0 days', '00:00:00'), 'note' => 'Från Help Scout #2 JEAB Solceller lead komplett anläggning sol+batteri+laddbox | Önskar', 'company' => 'jarfalla', 'created_at' => $now, 'updated_at' => $now],
    ['id' => 3, 'title' => 'Testgatan 3', 'customer_name' => 'Test Tre', 'due_at' => $at('-2 days'), 'note' => 'Bor i Solna, vill ha offert', 'company' => 'jarfalla', 'created_at' => $now, 'updated_at' => $now],
    ['id' => 4, 'title' => 'Testgatan 4', 'customer_name' => 'Test Fyra', 'due_at' => $at('-1 days'), 'tags' => ['admin'], 'company' => 'jarfalla', 'created_at' => $now, 'updated_at' => $now],
    ['id' => 5, 'title' => 'Testgatan 5', 'customer_name' => 'Test Fem', 'due_at' => $at('-3 days'), 'completed_at' => $now, 'note' => '13 st paneler', 'phone' => '0701234568', 'company' => 'jarfalla', 'created_at' => $now, 'updated_at' => $now],
    ['id' => 6, 'title' => 'Testgatan 6', 'customer_name' => 'Test Sex', 'due_at' => $at('+3 days'), 'company' => 'jarfalla', 'created_at' => $now, 'updated_at' => $now],
    ['id' => 7, 'title' => 'Testgatan 7', 'customer_name' => 'Test Sju', 'due_at' => $at('-1 days'), 'tags' => ['admin'], 'completed_at' => $now, 'company' => 'jarfalla', 'created_at' => $now, 'updated_at' => $now],
];
$ids = static fn (array $list): array => array_map(static fn (array $r): int => (int) $r['id'], $list);

// ---- 1. Försenat ---------------------------------------------------------------------------
pp_assert(jeab_reminder_is_late($rows[0]) && jeab_reminder_is_late($rows[2]), 'datum passerat = försenat');
pp_assert(!jeab_reminder_is_late($rows[1]), 'idag = inte försenat');
pp_assert(!jeab_reminder_is_late($rows[4]), 'klar = aldrig försenat');
pp_assert(!jeab_reminder_is_late($rows[5]), 'framtida = inte försenat');
$todayList = jeab_filter_reminders([$rows[1], $rows[0], $rows[2]], 'today', ['mode' => 'none', 'tag' => null], '');
pp_assert($ids($todayList) === [1, 3, 2], 'Idag: försenade först (ordningen inom grupp behålls), sedan dagens');

// ---- 4. Siffror följer taggfiltret ------------------------------------------------------------
$none = jeab_reminder_counts($rows, ['mode' => 'none', 'tag' => null]);
$all = jeab_reminder_counts($rows, ['mode' => 'all', 'tag' => null]);
$admin = jeab_reminder_counts($rows, ['mode' => 'tag', 'tag' => 'admin']);
foreach (['today', 'done', 'all', 'waiting'] as $v) {
    $mode = ['mode' => 'none', 'tag' => null];
    pp_assert($none[$v] === count(jeab_filter_reminders($rows, $v, $mode, '')), "utan tagg: {$v}-siffran = listan");
    pp_assert($admin[$v] === count(jeab_filter_reminders($rows, $v, ['mode' => 'tag', 'tag' => 'admin'], '')), "#admin: {$v}-siffran = listan");
}
pp_assert($none['all'] === 5 && $all['all'] === 7 && $admin['all'] === 2, 'Alla räknar öppna och klara: 5 utan tagg, 7 alla taggar, 2 #admin');
pp_assert($none['all'] >= $none['done'] && $all['all'] >= $all['done'] && $admin['all'] >= $admin['done'], 'Klara är inte fler än Alla');
pp_assert($none['done'] === 1 && $all['done'] === 2 && $admin['done'] === 1, 'Klara följer taggfiltret');
pp_assert(jeab_reminder_counts($rows) === $all, 'utan filter = som förut');

// ---- Rad: inga chips, ring, Klara utan knappar ---------------------------------------------------
pp_req(['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'today', 'tag' => 'none']);
$r1 = jeab_reminder_row($rows[0], false);
pp_assert(str_contains($r1, 'class="item is-late') , 'försenad rad har is-late');
$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/paminnelser.css');
$lateRules = [];
preg_match_all('/([^{}]*is-late[^{}]*)\{([^}]*)\}/', $css, $mm, PREG_SET_ORDER);
foreach ($mm as $m) {
    $lateRules[] = [trim($m[1]), trim($m[2])];
}
pp_assert(count($lateRules) === 1 && str_contains($lateRules[0][0], '.item-due-chip') && str_contains($lateRules[0][0], '.item-due.is-late .due'), 'försenat: bara datumtexten (stängd och öppen rad) har en egen regel');
pp_assert(preg_match('/^color:\s*var\(--late-date\);$/', $lateRules[0][1]) === 1, 'försenat: bara dämpad färg på datumet');
pp_assert(!preg_match('/is-late[^{]*\{[^}]*(background|border|box-shadow|--danger)/', $css), 'försenat: ingen bakgrund, kant eller röd');
pp_assert(str_contains($css, '--late-date: #c98a84;') && str_contains($css, 'html.theme-light body.page-reminders {') && str_contains($css, '--late-date: #a2514a;'), 'dämpad röd i mörkt och ljust läge');
pp_assert(!str_contains($r1, 'item-chip') && !str_contains($r1, 'chip-avatar'), 'inga namn-/produktchips på listkortet');
pp_assert(!str_contains($r1, 'Ansvarig: '), 'ansvarig står inte på listkortet');
pp_assert(str_contains($r1, 'class="item-call" href="tel:'), 'ring-knapp på raden');
pp_assert(!str_contains($r1, 'data-js="snooze"') && !str_contains($r1, 'data-js="tap"') && !str_contains($r1, 'item-head-open'), 'stängt kort ritar inte knuff, stämpel eller redigerare');
$r1open = jeab_reminder_row($rows[0], true);
pp_assert(str_contains($r1open, 'data-js="snooze"') && str_contains($r1open, 'data-js="tap"'), 'öppet kort har knuff och kontaktknappar');
pp_assert(substr_count($r1, 'class="item-tap-total"') === 0, 'inga kontakter = ingen siffra (oförändrat)');
$r1b = jeab_reminder_row($rows[0] + ['ringt' => 2], false);
pp_assert(preg_match('#<div class="item-shut-meta">.*?<span class="item-tap-total"[^>]*>2</span>#s', $r1b) === 1, 'kontaktsiffran står kvar på stängd rad som förut');
$r5 = jeab_reminder_row($rows[4], false);
pp_assert(!str_contains($r5, 'data-js="snooze"') && !str_contains($r5, 'data-js="tap"'), 'Klara: ingen knuff och inga kontaktknappar');
pp_assert(!str_contains($r5, 'item-call') && !str_contains($r5, 'is-late'), 'Klara: ingen ring-knapp, inte försenad');
pp_assert(!str_contains($r5, 'contact-edit-form'), 'stängt klart kort ritar inte kontaktformuläret');
$r5open = jeab_reminder_row($rows[4], true);
pp_assert(str_contains($r5open, 'contact-digits'), 'öppet klart kort visar numret');

// ---- Sidan: grupper, flikar, sök ----------------------------------------------------------
function pp_page(array $visible, string $view, string $search = ''): string
{
    $counts = jeab_reminder_counts($visible);
    $visibleTotal = count($visible);
    return require dirname(__DIR__) . '/src/pages/reminders.php';
}
pp_req(['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'today', 'tag' => 'none', 'who' => 'all']);
$html = pp_page($todayList, 'today');
pp_assert(preg_match('#data-group="late">\s*<h2[^>]*>Försenat.*?<strong>2</strong>#s', $html) === 1, 'Försenat-rubrik med 2');
pp_assert(preg_match('#data-group="today">\s*<h2[^>]*>Idag.*?<strong>1</strong>#s', $html) === 1, 'Idag-rubrik med 1');
$pos = static fn (string $needle): int => (int) strpos($html, $needle);
pp_assert($pos('data-group="late"') < $pos('id="r-1"') && $pos('id="r-3"') < $pos('data-group="today"') && $pos('data-group="today"') < $pos('id="r-2"'), 'ordning: Försenat, försenade, Idag, dagens');
pp_assert(!str_contains($html, 'crm-section-head'), 'ingen dubbel Idag-rubrik');
$html = pp_page([$rows[1]], 'today');
pp_assert(preg_match('#data-group="late" hidden#', $html) === 1, 'inga försenade: Försenat dold');
$html = pp_page([], 'today');
pp_assert(str_contains($html, 'crm-section-head') && !str_contains($html, 'data-groups'), 'tom Idag: vanlig rubrik');

pp_req(['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'today', 'tag' => 'all', 'q' => 'test', 'who' => 'all', 'ws' => (string) time()]);
$html = pp_page($rows, 'all', 'test');
pp_assert(!str_contains($html, 'smart-tile is-all is-on') && !preg_match('#smart-tile [a-z-]+ is-on#', $html), 'sök: ingen flik hoppar till Alla');
pp_assert(str_contains($html, '>Sökträffar<'), 'sök: rubriken Sökträffar');
pp_assert(str_contains($html, '<input type="hidden" name="v" value="today">'), 'sökrutan minns vyn man kom från');
pp_assert(str_contains($html, '<input type="hidden" name="who" value="all">'), 'sök behåller personvalet');
pp_assert(preg_match('#class="apple-search-clear" href="[^"]*v=today#', $html) === 1, 'rensa sök: tillbaka till Idag');
$scope = jeab_reminder_list_scope('today', ['mode' => 'all', 'tag' => null], 'test');
pp_assert($scope['view'] === 'all', 'sök söker fortfarande i allt inkl. klara (#261)');

pp_req(['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'all', 'tag' => 'admin', 'who' => 'all', 'ws' => (string) time()]);
$html = pp_page([], 'all');
pp_assert(preg_match('#smart-tile is-done" href="[^"]*tag=admin#', $html) === 1, 'Klara-fliken bär taggfiltret');

// ---- 6. Personval mellan bolag ------------------------------------------------------------
pp_req(['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'all', 'tag' => 'none', 'who' => 'all', 'ws' => (string) time()]);
pp_assert(str_contains(jeab_chrome_company_url('reminders', 'malmo'), 'who=all'), 'Alla följer med till Malmö');
pp_req(['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'all', 'tag' => 'none', 'who' => 'Roberto', 'ws' => (string) time()]);
$toMalmo = html_entity_decode(jeab_chrome_company_url('reminders', 'malmo'));
pp_assert(!str_contains($toMalmo, 'who=') && str_contains($toMalmo, 'co=malmo') && str_contains($toMalmo, 'v=all'), 'Roberto finns inte i Malmö: Malmös eget val, vyn följer med');
pp_assert(str_contains(html_entity_decode(jeab_chrome_company_url('reminders', 'jarfalla')), 'who=Roberto'), 'kvar i Järfälla: Roberto');
pp_req(['p' => 'reminders', 'co' => 'malmo', 'v' => 'all', 'tag' => 'none']);
pp_assert(jeab_selected_company() === 'malmo' && jeab_reminder_who_filter() === null, 'Malmö utan val: förvalet (agentkoden har ingen Malmö-person = Alla)');
$back = html_entity_decode(jeab_chrome_company_url('reminders', 'jarfalla'));
pp_assert(!str_contains($back, 'who='), 'tillbaka till Järfälla utan who');
pp_req(['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'all', 'tag' => 'none']);
pp_assert(jeab_reminder_who_filter() === 'Roberto', 'Järfälla minns Roberto efter Malmö');
pp_req(['p' => 'reminders', 'co' => 'jarfalla']);
pp_assert(jeab_reminder_who_filter() === null, 'menyn nollställer till Alla för den delade koden');

// ---- 7. Klar/Ångra: done=1 vänder inte en redan klar ---------------------------------------
$store = jeab_store();
$created = jeab_create_reminder($store, 'Testgatan 9');
$id = (int) ($created['id'] ?? 0);
pp_assert($id > 0, 'testpåminnelse skapad');
$r = jeab_toggle_reminder($store, $id, true);
pp_assert(!empty($r['completed_at']), 'done=1: klar');
$r = jeab_toggle_reminder($store, $id, true);
pp_assert(!empty($r['completed_at']), 'done=1 igen: fortfarande klar (ingen dubbelvändning)');
$index = file_get_contents(dirname(__DIR__) . '/index.php');
pp_assert(str_contains($index, "array_key_exists('done', \$_POST)"), 'reminder.toggle läser done');
pp_assert(str_contains($index, "\$intent === 'page'"), 'intent=page ger listan som JSON');
pp_assert(str_contains($index, 'jeab_reminder_counts($countRows, $tagFilter, $search)'), 'siffrorna räknas med taggfiltret');

// ---- 5/7/9: klientkoden finns ----------------------------------------------------------------
$js = file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
foreach (['softNavigateReminders', 'SEARCH_DEBOUNCE_MS', 'markReminderDone', 'Ångra', "value=\"2d\"]", 'dx > 0', 'paintReminderGroups'] as $needle) {
    pp_assert(str_contains($js, $needle), "app.js: {$needle}");
}
pp_assert(str_contains($js, 'markReminderDone(check, true)'), 'svep höger markerar klar och stänger lead');
pp_assert(!str_contains($js, 'höger: ingenting'), 'svep höger är inte längre dött');

echo "ALLA OK\n";
