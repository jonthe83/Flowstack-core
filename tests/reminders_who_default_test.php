<?php

declare(strict_types=1);

/**
 * Påminnelser: personfiltret förväljer den inloggade, ett aktivt val följer med medan man
 * arbetar (bläddring, Visa fler, sök, tillbaka från en påminnelse) och menyn nollställer.
 * Inloggning utan person = Alla.
 */

$tmp = sys_get_temp_dir() . '/jeab-rem-who-' . getmypid();
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

function rw_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** En sidladdning: inloggad som $email, adress $query. Sessionen lever vidare mellan anropen. */
function rw_req(?string $email, array $query, string $method = 'GET'): void
{
    $_GET = $query;
    $_SERVER['REQUEST_METHOD'] = $method;
    if ($email === null) {
        unset($_SESSION['jeab_email']);
    } else {
        $_SESSION['jeab_email'] = $email;
    }
}

function rw_page(): string
{
    $view = 'all';
    $counts = ['today' => 0, 'scheduled' => 0, 'all' => 0, 'waiting' => 0, 'done' => 0];
    $visible = [];
    $search = (string) ($_GET['q'] ?? '');
    return require dirname(__DIR__) . '/src/pages/reminders.php';
}

/** Vilket omfattningspiller som är på: mina, alla, eller tomt när ett gammalt who= gäller. */
function rw_on(string $html): string
{
    if (preg_match('#<div class="cal-filter crm-who">(.*?)</div>\s*</div>#s', $html, $m) !== 1) {
        return '?';
    }
    $on = [];
    foreach (preg_split('#</a>#', $m[1]) as $chip) {
        if (!str_contains($chip, 'is-on')) {
            continue;
        }
        if (preg_match('#data-scope="(mina|alla)"#', $chip, $n) === 1) {
            $on[] = $n[1];
        }
    }
    return implode(',', $on);
}

$jl = 'demo@example.test';
foreach (jeab_people() as $p) {
    if ($p['id'] === 'SamL') {
        $jl = $p['email'];
    }
}

// ---- Koppling inloggning -> person ------------------------------------------------------
rw_assert((jeab_person_by_login($jl)['id'] ?? '') === 'SamL', 'SamL:s egen e-post -> Sam L');
rw_assert(jeab_person_by_login('agenter@flowstack.example.test') === null, 'delad agentkod är ingen person');
rw_assert((jeab_person_by_login('ELIN@jarfalla.example.test')['name'] ?? '') === 'Elin', 'Elin, skiftläge spelar ingen roll');
rw_assert(jeab_person_by_login('okand@flowstack.example.test') === null, 'okänd inloggning -> ingen person');
rw_assert(jeab_person_by_login(null) === null && jeab_person_by_login('') === null, 'utan e-post -> ingen person');
foreach (['Roberto', 'Elin', 'Niman', 'Isaq', 'SamW', 'Abol', 'Kim'] as $id) {
    $row = null;
    foreach (jeab_people() as $p) {
        if ($p['id'] === $id) {
            $row = $p;
        }
    }
    rw_assert($row !== null && (jeab_person_by_login($row['email'])['id'] ?? '') === $id, "inloggning {$id} -> {$row['name']}");
}

// ---- Menyn: förval = jag -----------------------------------------------------------------
rw_req('agenter@flowstack.example.test', ['p' => 'reminders', 'co' => 'jarfalla']);
rw_assert(jeab_reminder_who_filter() === null && jeab_is_admin(), 'menyn som agentkoden: Alla, och admin');
rw_assert(rw_on(rw_page()) === 'alla', 'pillret Alla är på');
rw_req($jl, ['p' => 'reminders', 'co' => 'jarfalla']);
$html = rw_page();
rw_assert(jeab_auth_user_person() === null && jeab_reminder_who_filter() === null && rw_on($html) === 'alla' && !str_contains($html, 'Mina visar Sam'), 'menyn som Sam utan huvud är Alla');
rw_req('saljare4@jarfalla.example.test', ['p' => 'reminders', 'co' => 'jarfalla']);
$html = rw_page();
rw_assert(jeab_reminder_who_filter() === 'Elin' && rw_on($html) === 'mina' && str_contains($html, 'Mina visar Elin.'), 'menyn som Elin: Mina visar Elin');
rw_assert(preg_match('#data-scope="mina"[^>]*>Mina<span class="chip-count">#', $html) === 1 && !str_contains($html, 'chip-who-name'), 'Mina-pillen har bara texten Mina och räknare');
rw_assert(str_contains($html, '>Alla<span class="chip-count">'), 'Elin kan välja Alla');
rw_assert(!str_contains($html, '>Byt<'), 'egen inloggning har inget Byt');
rw_req('okand@flowstack.example.test', ['p' => 'reminders', 'co' => 'jarfalla']);
rw_assert(jeab_reminder_who_filter() === null && rw_on(rw_page()) === 'alla', 'okänd inloggning: Alla är på');
rw_req(null, ['p' => 'reminders', 'co' => 'jarfalla']);
rw_assert(rw_on(rw_page()) === 'alla', 'utan person: Alla är på');

// ---- Aktivt val följer med medan man arbetar ----------------------------------------------
rw_req('agenter@flowstack.example.test', ['p' => 'reminders', 'co' => 'jarfalla']);
$html = rw_page();
rw_assert(preg_match('#href="([^"]*who=all[^"]*)">Alla<span class="chip-count">#', $html, $m) === 1, 'Alla-pillret har who=all');
parse_str((string) parse_url(html_entity_decode($m[1]), PHP_URL_QUERY), $q);
rw_req('agenter@flowstack.example.test', $q);
$html = rw_page();
rw_assert(jeab_reminder_who_filter() === null && rw_on($html) === 'alla', 'klick på Alla: Alla på');
rw_assert(str_contains($html, '<input type="hidden" name="who" value="all">'), 'sökrutan bär who=all');
$more = jeab_reminders_url(['n' => 50, 'open' => null]);
rw_assert(str_contains($more, 'who=all'), 'Visa fler / bläddring bär who=all');
rw_req('agenter@flowstack.example.test', ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'all', 'tag' => 'all', 'q' => 'Edman', 'who' => 'all']);
rw_assert(jeab_reminder_who_filter() === null, 'sök med who=all: Alla');
// «Visa fler» hämtar via JS utan who i adressen: sessionen håller valet.
rw_req('agenter@flowstack.example.test', ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'done', 'tag' => 'all', 'intent' => 'more', 'offset' => '25']);
rw_assert(jeab_reminder_who_filter() === null, 'Visa fler utan who i adressen: fortfarande Alla');
// Tillbaka från inköpssidan (who saknas när Alla är valt).
$back = jeab_purchase_url(['id' => 7, 'co' => 'jarfalla']);
rw_assert(!str_contains($back, 'who='), 'inköpslänk med Alla bär inget namn');
rw_req('agenter@flowstack.example.test', ['p' => 'purchase', 'id' => '7', 'step' => '1', 'co' => 'jarfalla']);
$ret = jeab_reminders_url(['open' => 7, 'co' => 'jarfalla']);
rw_assert(str_contains($ret, 'who=all') && str_contains($ret, 'open=7'), 'tillbaka från påminnelsen: Alla kvar');
rw_req('agenter@flowstack.example.test', ['p' => 'reminders', 'co' => 'jarfalla', 'open' => '7', 'flash' => 'Sparad.']);
rw_assert(rw_on(rw_page()) === 'alla', 'efter sparad påminnelse (redirect med open/flash): Alla kvar');
// Annan person vald följer också med. Inget namnpiller är tänt.
rw_req('agenter@flowstack.example.test', ['p' => 'reminders', 'co' => 'jarfalla', 'who' => 'Roberto', 'v' => 'all', 'tag' => 'all', 'ws' => (string) time()]);
rw_assert(jeab_reminder_who_filter() === 'Roberto' && rw_on(rw_page()) === '', 'who=Roberto filtrerar utan att tända Mina eller Alla');
rw_req('agenter@flowstack.example.test', ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'done', 'tag' => 'all', 'intent' => 'sync']);
rw_assert(jeab_reminder_who_filter() === 'Roberto', 'Roberto kvar vid synk utan who');
// Kalenderns who läcker inte in i Påminnelser.
rw_req('agenter@flowstack.example.test', ['p' => 'bookings', 'co' => 'jarfalla', 'who' => 'Elin', 'job' => 'all']);
rw_assert(jeab_reminder_who_filter() === 'Roberto', 'who på kalendern ändrar inte Påminnelsers val');

// ---- Menyn igen: tillbaka till jag --------------------------------------------------------
rw_req('agenter@flowstack.example.test', ['p' => 'reminders', 'co' => 'jarfalla']);
rw_assert(rw_on(rw_page()) === 'alla', 'menyn igen: Alla');
rw_req('agenter@flowstack.example.test', ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'done', 'tag' => 'all', 'intent' => 'more', 'offset' => '25']);
rw_assert(jeab_reminder_who_filter() === null, 'efter menyn glöms Roberto även för Visa fler');
// Menylänkarna saknar who.
rw_assert(!str_contains(jeab_url('index.php?p=reminders'), 'who='), 'menylänken saknar who');
$layout = file_get_contents(dirname(__DIR__) . '/src/layout.php');
rw_assert(str_contains($layout, "http_build_query(['p' => \$slug, 'co' => \$company])"), 'flikens länk bär valt bolag');
$tabAt = strpos($layout, 'function jeab_tab_url');
$tabFn = $tabAt === false ? '' : substr($layout, $tabAt, 420);
rw_assert($tabFn !== '' && !str_contains($tabFn, 'who'), 'flikens länk saknar who');

// ---- Gemensamma filterpiller rörs inte ------------------------------------------------------
rw_req('agenter@flowstack.example.test', ['p' => 'bookings', 'co' => 'jarfalla']);
rw_assert(jeab_filter_login_preset('jarfalla') === ['who' => null, 'job' => null], 'kalender/ordrar: agentkoden har fortfarande Alla som förval');
rw_req('saljare4@jarfalla.example.test', ['p' => 'bookings', 'co' => 'jarfalla']);
rw_assert(jeab_filter_login_preset('jarfalla') === ['who' => 'Elin', 'job' => 'laddbox'], 'kalender/ordrar: Elins förval oförändrat');

// ---- Malmö har eget val -------------------------------------------------------------------
rw_req('agenter@flowstack.example.test', ['p' => 'reminders', 'co' => 'malmo', 'who' => 'all', 'v' => 'all', 'tag' => 'all']);
rw_assert(jeab_reminder_who_filter() === null, 'Malmö: Alla');
rw_req('agenter@flowstack.example.test', ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'all', 'tag' => 'all']);
rw_assert(jeab_reminder_who_filter() === null, 'Järfälla påverkas inte av valet i Malmö');

echo "ALLA OK\n";
