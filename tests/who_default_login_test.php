<?php

declare(strict_types=1);

/**
 * Förval = inloggad person på Påminnelser och Ordrar:
 * - kaka utfärdad innan koden fick e-post (v3 med «-») känns igen via kodens fingeravtryck,
 * - kod utan e-post kan kopplas med config login_people «fp:<16 hex>»,
 * - ett sparat personval (t.ex. Alla) glöms efter en stund och gammalt sessionsformat glöms,
 * - Ordrar och Kalendern förväljer personen även utan produktregel (Sam L); Alla går att välja.
 */

$tmp = sys_get_temp_dir() . '/jeab-who-login-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');

$hashJ = password_hash('kod-sam', PASSWORD_DEFAULT);
$hashAnon = password_hash('kod-utan-epost', PASSWORD_DEFAULT);
$hashOther = password_hash('kod-okand', PASSWORD_DEFAULT);
$fpAnon = substr(hash('sha256', $hashAnon), 0, 16); // samma som jeab_auth_hash_fingerprint
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => bin2hex(random_bytes(16)),
    'hashes' => [
        ['email' => 'agenter@flowstack.example.test', 'hash' => $hashJ],
        $hashAnon,
        $hashOther,
    ],
]));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'passwords_file' => $tmp . '/passwords.json',
    'login_people' => ['fp:' . $fpAnon => 'SamL'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
$jlEmail = '';
foreach (jeab_people() as $p) {
    if ($p['id'] === 'SamL') {
        $jlEmail = (string) $p['email'];
    }
}
require dirname(__DIR__) . '/src/layout.php';
jeab_auth_data(true);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function wl_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** Ny sidladdning med bara kakan (ingen e-post i sessionen). */
function wl_cookie(?string $value, array $query): void
{
    $_GET = $query;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SESSION['jeab_email']);
    if ($value === null) {
        unset($_COOKIE[JEAB_AUTH_COOKIE]);
    } else {
        $_COOKIE[JEAB_AUTH_COOKIE] = $value;
    }
}

function wl_email(?string $email, array $query): void
{
    $_GET = $query;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_COOKIE[JEAB_AUTH_COOKIE]);
    if ($email === null) {
        unset($_SESSION['jeab_email']);
    } else {
        $_SESSION['jeab_email'] = $email;
    }
}

wl_assert(jeab_auth_ready(), 'testkoderna laddade');
wl_assert((jeab_config()['passwords_file'] ?? '') === $tmp . '/passwords.json', 'testconfig används');

// ---- Kakan: koden avgör personen -----------------------------------------------------------
$menu = ['p' => 'reminders', 'co' => 'jarfalla'];
wl_assert(jeab_auth_hash_fingerprint($hashAnon) === $fpAnon, 'fingeravtryck som i auth.php');
$old = jeab_auth_issue_value(null, $hashJ); // kaka från innan koden fick e-post
wl_cookie($old, $menu);
wl_assert(jeab_auth_value_ok($old), 'gammal kaka (utan e-post) gäller fortfarande');
wl_assert(jeab_auth_user_email() === 'agenter@flowstack.example.test', 'gammal kaka: kodens nuvarande e-post används');
wl_assert(jeab_auth_user_person() === null && jeab_is_admin(), 'gammal kaka: delad agentkod är ingen person och är admin');
wl_assert(jeab_reminder_who_filter() === null, 'gammal kaka: Påminnelser förväljer Alla');
$fresh = jeab_auth_issue_value('agenter@flowstack.example.test', $hashJ);
wl_cookie($fresh, $menu);
wl_assert(jeab_auth_user_email() === 'agenter@flowstack.example.test', 'ny kaka: e-post som förut');
wl_assert(jeab_auth_user_person() === null && jeab_is_admin(), 'ny kaka: fortfarande ingen person och är admin');
wl_cookie(jeab_auth_issue_value(null, $hashAnon), $menu);
wl_assert(jeab_auth_user_email() === null, 'kod utan e-post: ingen e-post');
wl_assert((jeab_auth_user_person()['id'] ?? '') === 'SamL', 'kod utan e-post kopplad via fp: -> Sam L');
wl_assert(!jeab_is_admin(), 'config-koppling till Sam L är inte admin');
wl_assert(jeab_reminder_who_filter() === 'Sam L', 'kod utan e-post men kopplad: Sam L förvald');
wl_cookie(jeab_auth_issue_value(null, $hashOther), $menu);
wl_assert(jeab_auth_user_person() === null && jeab_reminder_who_filter() === null, 'okopplad kod: Alla');
wl_assert(jeab_person_by_login_fingerprint('zz') === null && jeab_person_by_login_fingerprint(null) === null, 'trasigt fingeravtryck: ingen person');

// ---- Sparat val glöms -----------------------------------------------------------------------
wl_email($jlEmail, ['p' => 'reminders', 'co' => 'jarfalla', 'who' => 'all', 'v' => 'all', 'tag' => 'all', 'ws' => (string) time()]);
wl_assert(jeab_reminder_who_filter() === null, 'Alla valt');
wl_email($jlEmail, ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'today', 'open' => '5']);
wl_assert(jeab_reminder_who_filter() === null, 'medan man arbetar: Alla kvar');
$_SESSION['jeab_reminder_who']['jarfalla']['at'] = time() - JEAB_REMINDER_WHO_TTL - 5;
wl_email($jlEmail, ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'today', 'open' => '5']);
wl_assert(jeab_reminder_who_filter() === null && !jeab_is_admin(), 'gammalt Alla-val glöms: e-post utan huvud är fortfarande Alla');
$_SESSION['jeab_reminder_who']['jarfalla'] = 'all'; // format före denna ändring
wl_email($jlEmail, ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'today', 'flash' => 'Sparad.']);
wl_assert(jeab_reminder_who_filter() === null, 'gammalt sessionsformat (Alla) glöms till Alla utan huvud');
wl_assert(!isset($_SESSION['jeab_reminder_who']['jarfalla']), 'gammalt värde rensat');
wl_email($jlEmail, ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'all', 'tag' => 'all', 'intent' => 'page']);
wl_assert(jeab_reminder_who_filter() === null, 'AJAX-byte utan who: Alla utan huvud');
$url = jeab_reminders_url(['v' => 'done', 'open' => null]);
wl_assert(!str_contains($url, 'who=Sam'), 'flikar utan huvud bär inte Sam');
$switch = jeab_chrome_company_url('reminders', 'malmo');
wl_assert(!str_contains($switch, 'who=all'), 'bolagsbyte tar inte med Alla när förvalet gäller');

// ---- Ordrar ---------------------------------------------------------------------------------
wl_email('agenter@flowstack.example.test', ['p' => 'ordrar', 'co' => 'jarfalla']);
wl_assert(jeab_auth_user_person() === null && jeab_is_admin(), 'Ordrar: agentkoden är ingen person och är admin');
wl_assert(jeab_filter_from_request('jarfalla', true) === ['who' => null, 'job' => null, 'explicit' => false], 'Ordrar som agentkoden: Alla');
wl_assert(jeab_filter_from_request('jarfalla') === ['who' => null, 'job' => null, 'explicit' => false], 'Kalendern oförändrad: Alla');
wl_email($jlEmail, ['p' => 'ordrar', 'co' => 'jarfalla']);
wl_assert(jeab_filter_from_request('jarfalla', true)['who'] === null, 'Ordrar som Sams e-post utan huvud: Alla');
wl_email($jlEmail, ['p' => 'ordrar', 'co' => 'jarfalla', 'who' => 'all', 'job' => 'all', 'ws' => (string) time()]);
wl_assert(jeab_filter_from_request('jarfalla', true) === ['who' => null, 'job' => null, 'explicit' => true], 'Ordrar: Alla går att välja');
wl_email($jlEmail, ['p' => 'ordrar', 'co' => 'jarfalla', 'who' => 'Roberto', 'job' => 'all', 'ws' => (string) time()]);
wl_assert(jeab_filter_from_request('jarfalla', true)['who'] === 'Roberto', 'Ordrar: annan person går att välja');
wl_email($jlEmail, ['p' => 'ordrar', 'co' => 'malmo']);
wl_assert(jeab_filter_from_request('malmo', true)['who'] === null, 'Ordrar Malmö: ingen koppling där -> Alla');
wl_email('saljare4@jarfalla.example.test', ['p' => 'ordrar', 'co' => 'jarfalla']);
wl_assert(jeab_filter_from_request('jarfalla', true) === ['who' => 'Elin', 'job' => 'laddbox', 'explicit' => false], 'Ordrar som Elin: oförändrat');
wl_email('saljare6@malmo.example.test', ['p' => 'ordrar', 'co' => 'malmo']);
wl_assert(jeab_filter_from_request('malmo', true)['who'] === 'Isaq', 'Ordrar som Isaq (utan produkt): Isaq');
wl_assert(jeab_filter_from_request('malmo')['who'] === null, 'generiskt filter utan personläge som Isaq: Alla');
// ---- Kalendern: samma förval som Ordrar (inloggad person), Alla går att välja --------------
wl_email('agenter@flowstack.example.test', ['p' => 'bookings', 'co' => 'jarfalla']);
wl_assert(jeab_booking_who_filter() === null && jeab_booking_job_filter() === null, 'Kalendern som agentkoden: Alla');
wl_email('agenter@flowstack.example.test', ['p' => 'bookings', 'co' => 'jarfalla', 'who' => 'all', 'job' => 'all']);
wl_assert(jeab_booking_who_filter() === null, 'Kalendern: Alla går att välja');
wl_email('agenter@flowstack.example.test', ['p' => 'bookings', 'co' => 'malmo']);
wl_assert(jeab_booking_who_filter() === null, 'Kalendern Malmö som Sam L: Alla (ingen koppling där)');
wl_email('saljare6@malmo.example.test', ['p' => 'bookings', 'co' => 'malmo']);
wl_assert(jeab_booking_who_filter() === 'Isaq', 'Kalendern som Isaq: Isaq');
wl_email('saljare4@jarfalla.example.test', ['p' => 'bookings', 'co' => 'jarfalla']);
wl_assert(jeab_booking_who_filter() === 'Elin' && jeab_booking_job_filter() === 'laddbox', 'Kalendern som Elin: Elin + Elbilsladdare som förut');
wl_email('agenter@flowstack.example.test', ['p' => 'bookings', 'co' => 'jarfalla', 'w' => '2', 'who' => 'all', 'job' => 'all']);
$calSwitch = jeab_chrome_company_url('bookings', 'malmo');
wl_assert(!str_contains($calSwitch, 'who=') && str_contains($calSwitch, 'w=2') && str_contains($calSwitch, 'co=malmo'), 'Kalender: kontorsbyte behåller veckan och börjar på förvalet');

wl_email('okand@flowstack.example.test', ['p' => 'ordrar', 'co' => 'jarfalla']);
wl_assert(jeab_filter_from_request('jarfalla', true)['who'] === null, 'Ordrar okänd inloggning: Alla');
$orders = [
    ['reminder_id' => 1, 'company' => 'jarfalla', 'person' => 'SamL'],
    ['reminder_id' => 2, 'company' => 'jarfalla', 'assignees' => ['Sam L']],
    ['reminder_id' => 3, 'company' => 'jarfalla', 'assignees' => ['Roberto']],
    ['reminder_id' => 4, 'company' => 'jarfalla'],
];
$hit = array_map(static fn (array $r): int => (int) $r['reminder_id'], jeab_filter_orders($orders, 'Sam L', null));
wl_assert($hit === [1, 2], 'Ordrar: Sam L:s ordrar (ansvarig/tilldelad), inte andras');
$page = file_get_contents(dirname(__DIR__) . '/src/pages/ordrar.php');
wl_assert(str_contains($page, 'jeab_filter_from_request($company, true)'), 'Ordrar-listan använder personförvalet');

echo "ALLA OK\n";
