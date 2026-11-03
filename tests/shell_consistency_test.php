<?php

declare(strict_types=1);

/** Delad sidram: rubrik, bolagsväljare, Inkorg-kort och kalenderfilter. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-shell-' . bin2hex(random_bytes(4));
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

function shell_ok(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'co' => 'jarfalla'];
$_SESSION['jeab_inkorg_box'] = 'jarfalla';
$chrome = jeab_chrome_company_html('inkorg');
shell_ok(preg_match('/<a class="chip is-on"[^>]*>Järfälla<\/a>/', $chrome) === 1, 'Järfälla är vald i bolagsväljaren');
shell_ok(preg_match('/<a class="chip"[^>]*>Malmö<\/a>/', $chrome) === 1, 'Malmö är inte markerad när Järfälla är aktiv');
shell_ok(!str_contains($chrome, 'chip is-on">Malmö'), 'Malmö saknar aktiv klass');

$root = dirname(__DIR__);
$app = (string) file_get_contents($root . '/assets/css/app.css');
$ink = (string) file_get_contents($root . '/assets/css/inkorg.css');
$pam = (string) file_get_contents($root . '/assets/css/paminnelser.css');
shell_ok(!str_contains($app, 'body.page-inkorg .main') || !preg_match('/body\.page-inkorg \.main\s*\{[^}]*max-width:\s*100%/', $app), 'Inkorg använder inte full bredd i app.css');
shell_ok(!preg_match('/body\.page-inkorg \.main\s*\{[^}]*max-width:\s*100%/', $ink), 'Inkorg använder inte full bredd i inkorg.css');
shell_ok(str_contains($app, 'max-width: var(--container);'), 'huvudinnehållet begränsas av --container');
shell_ok(preg_match('/\.week-nav-shift \.icon-btn\s*\{[^}]*color:\s*var\(--brand\)/', $app) === 1, 'kalenderns pilar är mint');
shell_ok(!str_contains($app, '#0A84FF'), 'kalendern använder inte den blå pilfärgen');
shell_ok(!str_contains($pam, 'IBM Plex Sans'), 'Påminnelser ärver Inter');
shell_ok(str_contains($app, 'font-size: 24px') && str_contains($app, 'h1.crm-title'), 'sidrubriken är 24px');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla'];
$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';
$inbox = (string) include $root . '/src/pages/inkorg.php';
shell_ok(str_contains($inbox, '<h1 class="crm-title">Inkorg</h1>'), 'Inkorg-rubriken har ingen räknare');
shell_ok(!str_contains($inbox, 'crm-count'), 'räknaren efter rubriken är borta');
shell_ok(str_contains($inbox, 'class="crm-gear"') && str_contains($inbox, '>Regler</a>') && str_contains($inbox, '>Vyer och etiketter</a>'), 'kugghjulet har Regler och Vyer');
shell_ok(!str_contains($inbox, 'ix-settings'), 'Regler och Vyer är inte filterchips');
preg_match_all('/<a class="smart-tile[^"]*".*?<\/a>/s', $inbox, $cards);
$cardText = implode("\n", $cards[0]);
shell_ok($cardText !== '' && !str_contains($cardText, 'Väntar på offert') && !str_contains($cardText, 'Papperskorg'), 'korten saknar Väntar på offert och Papperskorg');
shell_ok(str_contains($cardText, 'Historik'), 'Historik finns kvar som kort');
shell_ok(str_contains($inbox, '>Egna</a>') && str_contains($inbox, 'data-ix-scope="allas"') && str_contains($inbox, '>Alla</a>') && !str_contains($inbox, '>Allas</a>') && !str_contains($inbox, '>Alla typer</a>'), 'Egna och Alla ersätter typfiltren');
shell_ok(!str_contains($inbox, 'ix-type-more') && !str_contains($inbox, '>Filter</summary>'), 'filterknappen är borta');
$inboxSrc = (string) file_get_contents($root . '/src/pages/inkorg.php');
shell_ok(str_contains($inboxSrc, 'class="chip chip-primary crm-add"') && str_contains($inboxSrc, '+ Nytt mejl'), 'primärknappen sitter i sidhuvudet');

$reminders = $visible = [];
$view = 'today';
$counts = ['today' => 0, 'scheduled' => 0, 'all' => 0, 'waiting' => 0, 'done' => 0];
$tags = [];
$activeTag = null;
$search = '';
$flash = null;
$error = null;
$rem = (string) include $root . '/src/pages/reminders.php';
shell_ok(str_contains($rem, 'class="apple-eyebrow">Järfälla</p>') && str_contains($rem, '<h1 class="crm-title">Påminnelser</h1>'), 'Påminnelser har ort och rubrik');
shell_ok(!str_contains($rem, 'MINI-CRM') && str_contains($rem, '+ Lägg till'), 'Påminnelser har ingen MINI-CRM-rad');

$company = 'jarfalla';
$rows = [];
$open = null;
$ord = (string) include $root . '/src/pages/ordrar.php';
shell_ok(str_contains($ord, 'class="ordrar-eyebrow">Järfälla</p>') && str_contains($ord, '<h1 class="crm-title">Ordrar</h1>'), 'Ordrar har ort och rubrik');

$monday = jeab_week_monday(0);
$weekOffset = 0;
$bookings = [];
$selectedInstallers = [];
$fromReminder = null;
$cal = (string) include $root . '/src/pages/bookings.php';
shell_ok(str_contains($cal, '<h1 class="crm-title">Kalender</h1>') && str_contains($cal, '+ Boka ny tid'), 'Kalender har rubrik och primärknapp');
shell_ok(substr_count($cal, 'Boka ny tid') === 1, 'Kalender har en bokningsknapp');
shell_ok(str_contains($cal, 'cal-filter-people') && str_contains($cal, 'cal-filter-products') && !str_contains($cal, '>Filter</summary>'), 'personer och produkter är åtskilda');
shell_ok(str_contains($cal, 'chip-job'), 'produktfiltren syns');
