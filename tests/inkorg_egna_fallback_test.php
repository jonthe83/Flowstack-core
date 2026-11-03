<?php

declare(strict_types=1);

/**
 * Egna utan känd person visar Alla med en rad om det. Med känd person filtreras
 * Egna som förut. SQL- och json-läget använder samma person (jeab_egna_me_id,
 * sessionens kodperson räknas). Alla-klicket faller tillbaka på en vanlig
 * sidladdning när listan är upptagen. Inget utskick, ingen DDL i data/.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-egnafb-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/privat', 0775, true);
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
$fbHash = password_hash('SamLegna', PASSWORD_DEFAULT);
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => bin2hex(random_bytes(16)),
    'hashes' => [['email' => 'demo@example.test', 'hash' => $fbHash]],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'passwords_file' => $tmp . '/passwords.json',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PRIVAT_DIR'] = $tmp . '/privat';
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';
require_once $root . '/src/data_cutover_all.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$_SERVER['REQUEST_METHOD'] = 'GET';
$GLOBALS['_JEAB_AUTH_OK'] = ['' => true];

function fb(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function fb_reset(): void
{
    unset(
        $GLOBALS['_JEAB_LOGIN_IDENTITY'],
        $GLOBALS['_JEAB_INKORG_UNREAD'],
        $GLOBALS['_JEAB_INKORG_LIGHT'],
        $GLOBALS['_JEAB_INKORG_LIGHT_FP'],
        $GLOBALS['_JEAB_INKORG_THREADS']
    );
}

function fb_login(string $how): void
{
    global $fbHash;
    unset($_COOKIE[JEAB_AUTH_COOKIE], $_SESSION['jeab_code_person'], $_SESSION['jeab_email']);
    if ($how === 'code') {
        jeab_code_people_save(jeab_auth_hash_fingerprint($fbHash), 'SamL', 'prefix', 'SamL');
        $_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value('demo@example.test', $fbHash);
    } elseif ($how === 'session') {
        $_SESSION['jeab_code_person'] = 'SamL';
    }
    fb_reset();
}

function fb_scope(string $scope): void
{
    $_GET = ['p' => 'inkorg', 'ix_part' => 'all', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => $scope];
    fb_reset();
}

/** @return list<string> */
function fb_assignees(string $scope): array
{
    $out = [];
    foreach (jeab_inkorg_fast_scope_menu($scope) as $view) {
        $out[] = (string) (((array) ($view['filter'] ?? []))['assignee'] ?? '');
    }

    return array_values(array_unique($out));
}

$pdo = jeab_pdo_connect_sqlite();
foreach (['json', 'sql'] as $mode) {
    fb(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', $mode), "läge {$mode}");
    fb(jeab_inkorg_storage_mode() === $mode, "lagringsläget är {$mode}");

    // Okänd person: Egna blir Alla, utan personfilter.
    fb_login('none');
    fb_scope('egna');
    fb(jeab_egna_me_id() === '', "{$mode}: ingen person");
    fb(jeab_inkorg_egna_fallback(), "{$mode}: Egna faller tillbaka på Alla");
    fb(!in_array('none-person', fb_assignees('egna'), true), "{$mode}: menyn får inget none-person");
    fb(jeab_inkorg_fast_scope_menu('egna') === jeab_inkorg_fast_scope_menu('allas'), "{$mode}: Egna-menyn är Alla-menyn");
    $egna = jeab_inkorg_fast_prepare(false, 'list');
    fb_scope('allas');
    fb(!jeab_inkorg_egna_fallback(), "{$mode}: Alla visar ingen reservrad");
    $alla = jeab_inkorg_fast_prepare(false, 'list');
    fb((int) ($egna['total'] ?? -1) === (int) ($alla['total'] ?? -2), "{$mode}: samma antal rader som Alla");
    fb((array) ($egna['counts'] ?? []) == (array) ($alla['counts'] ?? []), "{$mode}: chip- och menytal som Alla");

    // Känd person via kod: Egna filtrerar som förut.
    fb_login('code');
    fb_scope('egna');
    fb(jeab_inkorg_me_id() === 'SamL' && jeab_egna_me_id() === 'SamL', "{$mode}: koden ger SamL");
    fb(!jeab_inkorg_egna_fallback(), "{$mode}: ingen reserv med känd person");
    fb(fb_assignees('egna') === ['me'], "{$mode}: Egna filtrerar på mig");
    fb(jeab_inkorg_scope_me_id() === 'SamL', "{$mode}: räknarens person är SamL");

    // Bara sessionens kodperson (json-vägens Egna): SQL-räknaren använder samma person.
    fb_login('session');
    fb_scope('egna');
    fb(jeab_inkorg_me_id() === '', "{$mode}: inloggningen ensam är okänd");
    fb(jeab_egna_me_id() === 'SamL', "{$mode}: sessionens kodperson räknas i Egna");
    fb(jeab_inkorg_scope_me_id() === 'SamL', "{$mode}: historik-räknaren får samma person som json");
    fb(!jeab_inkorg_egna_fallback(), "{$mode}: ingen reserv med sessionens person");
    fb_scope('allas');
    fb(jeab_inkorg_scope_me_id() === '', "{$mode}: Alla använder inloggningens person");
}

$page = (string) file_get_contents($root . '/src/pages/inkorg.php');
fb(str_contains($page, 'Ingen person är kopplad till din inloggning, visar alla'), 'reservraden finns på svenska');
fb(str_contains($page, "\$q(['scope' => \$scope, 'q' => ''"), 'Återställ behåller vald scope');
fb(!str_contains($page, "\$q(['scope' => 'egna', 'q' => ''"), 'Återställ tvingar inte Egna');
fb(str_contains($page, "if (left <= 0) { var be = new Error('busy'); be.busy = true; throw be; }"), 'upptagen lista blir ett fel, inte tyst null');
fb(str_contains($page, 'if (fromPop) { location.replace(show); } else { location.href = show; }'), 'Alla-klick laddar adressen när hämtningen misslyckas');
fb(str_contains($page, "'page.slot_busy', null, ['part' => \$part, 'scope' =>"), 'slot_busy-spåret har scope');
fb(!str_contains($page, "getAttribute('data-ix-scope') || 'egna'"), 'JS faller inte tillbaka på Egna');
$boot = (string) file_get_contents($root . '/src/bootstrap.php');
fb(str_contains($boot, "'part_enter.slot_busy', null, ['part' => \$partNow, 'scope' =>"), 'part_enter.slot_busy har scope');

echo "OK  inkorg Egna-reserv\n";
