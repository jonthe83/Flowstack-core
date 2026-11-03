<?php

declare(strict_types=1);

/**
 * Gemensamt filter: personal, produkter, inloggningsförval och träff på ordrar/bokningar.
 */

$tmp = sys_get_temp_dir() . '/jeab-filter-pills-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => [
        'driver' => 'json',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function filt_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function filt_as(?string $email, array $query = []): void
{
    $_GET = $query;
    if ($email === null) {
        unset($_SESSION['jeab_email'], $_SESSION['jeab_company']);
        return;
    }
    $_SESSION['jeab_email'] = $email;
}

filt_assert(jeab_filter_staff('jarfalla') === jeab_company_names('jarfalla'), 'personalpiller läser personregistret');
filt_assert(jeab_filter_products() === JEAB_JOB_TYPES, 'produktpiller är JEAB_JOB_TYPES');
foreach (['Roberto', 'Sam L', 'Elin', 'Niman'] as $name) {
    filt_assert(in_array($name, jeab_filter_staff('jarfalla'), true), 'Järfälla har ' . $name);
}
foreach (['Batteri', 'Sol', 'Elbilsladdare', 'Service'] as $label) {
    filt_assert(in_array($label, jeab_filter_products(), true), 'produkt ' . $label);
}

filt_as(null, ['co' => 'jarfalla']);
filt_assert(jeab_filter_login_preset('jarfalla') === ['who' => null, 'job' => null], 'utan inloggning är förvalet Alla');

filt_as('saljare4@jarfalla.example.test', ['co' => 'jarfalla']);
filt_assert(jeab_filter_login_preset('jarfalla') === ['who' => 'Elin', 'job' => 'laddbox'], 'Elin förväljer Elin + Elbilsladdare');
filt_assert(jeab_filter_from_request('jarfalla') === ['who' => 'Elin', 'job' => 'laddbox', 'explicit' => false], 'Elin utan query får förvalet');
filt_assert(jeab_reminder_who_filter() === 'Elin', 'Påminnelser använder samma personförval för Elin');

filt_as('saljare4@jarfalla.example.test', ['co' => 'jarfalla', 'who' => 'all', 'job' => 'all']);
$cleared = jeab_filter_from_request('jarfalla');
filt_assert($cleared['who'] === null && $cleared['job'] === null && $cleared['explicit'] === true, 'Alla slår ut Elins förval');

filt_as('saljare4@jarfalla.example.test', ['co' => 'jarfalla', 'who' => 'Roberto', 'job' => 'all']);
$override = jeab_filter_from_request('jarfalla');
filt_assert($override['who'] === 'Roberto' && $override['job'] === null, 'valt namn ersätter förvalet');

filt_as('saljare3@jarfalla.example.test', ['co' => 'jarfalla']);
filt_assert(jeab_filter_login_preset('jarfalla') === ['who' => 'Roberto', 'job' => 'batteri'], 'Roberto förväljer Roberto + Batteri');

filt_as('saljare5@jarfalla.example.test', ['co' => 'jarfalla']);
filt_assert(jeab_filter_login_preset('jarfalla') === ['who' => 'Niman', 'job' => 'batteri'], 'Niman förväljer Niman + Batteri');

filt_as('demo@example.test', ['co' => 'jarfalla']);
filt_assert(jeab_filter_login_preset('jarfalla') === ['who' => null, 'job' => null], 'Sam L förväljer Alla');
filt_assert(jeab_reminder_who_filter() === 'Sam L', 'Påminnelser utan produktförval stannar på Sam själv');
filt_assert(jeab_visible_company_names('jarfalla') === jeab_filter_staff('jarfalla'), 'Sam ser hela personalistan från samma källa');

filt_as('saljare4@jarfalla.example.test', ['co' => 'jarfalla']);
filt_assert(jeab_visible_company_names('jarfalla') === ['Elin'], 'Elin ser sitt namn, hämtat från samma personalista');

filt_as('saljare.syd@malmo.example.test', ['co' => 'malmo']);
filt_assert(jeab_filter_login_preset('malmo') === ['who' => 'Kim', 'job' => 'laddbox'], 'ny person utan egen regel följer job i registret');

filt_assert(jeab_calendar_filter_next(null, null, 'who', 'Elin') === ['who' => 'Elin', 'job' => 'all'], 'klick på person väljer bara personen');
filt_assert(jeab_calendar_filter_next('Elin', null, 'who', 'Elin') === ['who' => 'all', 'job' => 'all'], 'andra klicket på personen är Alla');
filt_assert(jeab_calendar_filter_next('Elin', 'laddbox', 'who', 'Elin') === ['who' => 'Elin', 'job' => 'all'], 'klick på redan vald person släpper produkten');
filt_assert(jeab_calendar_filter_next('Elin', 'laddbox', 'job', 'laddbox') === ['who' => 'all', 'job' => 'laddbox'], 'klick på redan vald produkt släpper personen');
filt_assert(jeab_calendar_filter_next('Elin', 'laddbox', 'job', 'batteri') === ['who' => 'all', 'job' => 'batteri'], 'klick på annan produkt byter produkt');

$booking = [
    'company' => 'jarfalla',
    'assignee' => 'Elin',
    'job_type' => 'laddbox',
];
filt_assert(jeab_booking_matches_filter($booking, 'Elin', 'laddbox'), 'bokning träffar Elin och Elbilsladdare');
filt_assert(!jeab_booking_matches_filter($booking, 'Roberto', 'laddbox'), 'bokning döljs för Roberto');
filt_assert(!jeab_booking_matches_filter($booking, 'Elin', 'batteri'), 'bokning döljs för Batteri');
filt_assert(jeab_booking_matches_filter($booking, null, null), 'bokning syns utan filter');

$batteri = ['company' => 'jarfalla', 'kind' => 'batteri', 'reminder_id' => 1];
$laddbox = ['company' => 'jarfalla', 'kind' => 'laddbox', 'reminder_id' => 2];
$sol = ['company' => 'jarfalla', 'kind' => 'sol', 'reminder_id' => 3];
filt_assert(jeab_order_matches_filter($batteri, 'Roberto', 'batteri'), 'batteriorder träffar Roberto + Batteri');
filt_assert(jeab_order_matches_filter($batteri, 'Niman', 'batteri'), 'batteriorder träffar Niman + Batteri');
filt_assert(!jeab_order_matches_filter($batteri, 'Elin', 'laddbox'), 'batteriorder döljs för Elin + Elbilsladdare');
filt_assert(jeab_order_matches_filter($laddbox, 'Elin', 'laddbox'), 'laddboxorder träffar Elin + Elbilsladdare');
filt_assert(!jeab_order_matches_filter($laddbox, 'Roberto', null), 'laddboxorder döljs för Roberto');
filt_assert(jeab_order_matches_filter($sol, 'Sam L', 'sol'), 'solorder träffar Sam L');
filt_assert(!jeab_order_matches_filter($sol, 'Niman', 'sol'), 'solorder döljs för Niman');

$only = jeab_filter_orders([$batteri, $laddbox, $sol], 'Elin', 'laddbox');
filt_assert(count($only) === 1 && (int) $only[0]['reminder_id'] === 2, 'orderlistan filtreras till Elins laddbox');

$href = static fn(array $params): string => 'index.php?' . http_build_query($params);
filt_as('saljare4@jarfalla.example.test', ['co' => 'jarfalla']);
$chips = jeab_filter_chips_html($href, 'Elin', 'laddbox', 'jarfalla');
filt_assert(str_contains($chips, '>Alla</a>'), 'pilleraden har Alla');
filt_assert(str_contains($chips, '>Elin</a>') && str_contains($chips, '>Elbilsladdare</a>'), 'pilleraden har Elin och Elbilsladdare');
filt_assert(substr_count($chips, ' is-on') === 2, 'Elin och Elbilsladdare är tända samtidigt');
filt_assert(str_contains($chips, 'who=all') && str_contains($chips, 'job=batteri'), 'produktlänken kan byta filter');

filt_as('saljare4@jarfalla.example.test', ['co' => 'jarfalla', 'p' => 'ordrar', 'who' => 'all', 'job' => 'all', 'ws' => (string) time()]);
$open = null;
$company = 'jarfalla';
$flash = null;
$error = null;
$rows = [];
$html = require dirname(__DIR__) . '/src/pages/ordrar.php';
filt_assert(is_string($html) && str_contains($html, 'ordrar-staff-filter'), 'Ordrar ritar filterraden');
filt_assert(str_contains($html, 'chip-job is-batteri') && str_contains($html, '>Service</a>'), 'Ordrar har samma produktpiller');
filt_assert(str_contains($html, 'data-scope="mina"') && str_contains($html, 'data-scope="alla"'), 'Ordrar har Mina och Alla');
filt_assert(!str_contains($html, 'who=Roberto'), 'personnamn är inte egna piller');
filt_assert(preg_match('/class="chip is-on"[^>]*data-scope="alla"/', $html) === 1, 'Alla är tänd när filtret är tömt');

echo "OK  filterpiller\n";
