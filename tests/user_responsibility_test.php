<?php

declare(strict_types=1);

/**
 * Användare: tabellen user_responsibility. Seed = dagens hårdkodade regler,
 * läs/skriv i SQLite och att ägarlogiken ger samma svar som före tabellen.
 */

$tmp = sys_get_temp_dir() . '/jeab-user-resp-' . getmypid();
@mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
require dirname(__DIR__) . '/src/bootstrap.php';

function ur(bool $ok, string $m): void
{
    if ($ok) {
        echo "OK  {$m}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$m}\n");
    exit(1);
}

ur(function_exists('jeab_user_responsibility_for'), 'modulen laddas av bootstrap');
ur(in_array('user_responsibility.php', jeab_bootstrap_core_files(), true) && in_array('user_responsibility.php', jeab_bootstrap_legacy_files(), true), 'modulen finns i kärnan och legacy-listan');

// Hårdkodade svar från före tabellen (948090b2).
$legacyLead = ['batteri' => ['Roberto', 'Roberto'], 'laddbox' => ['Elin', 'Elin'], 'sol' => ['SamL', 'Sam L'], 'sol+batteri' => ['SamL', 'Sam L'], 'okänd' => ['', '']];
$legacyNoEmail = ['malmo' => ['batteri' => 'Kim', 'laddbox' => 'Kim', 'sol' => 'Kim', 'x' => 'Kim'], 'jarfalla' => ['batteri' => 'Roberto', 'laddbox' => 'Elin', 'sol' => 'Sam L', 'sol+batteri' => 'Sam L', 'x' => 'Sam L']];
$legacyWebshop = ['malmo' => ['batteri' => 'Kim', 'laddbox' => 'Kim', 'sol' => 'Kim', 'x' => 'Kim'], 'jarfalla' => ['batteri' => 'Roberto', 'laddbox' => 'Elin', 'sol' => 'Sam L', 'x' => '']];
$legacyQuote = ['malmo' => ['batteri' => 'Kim', 'laddbox' => 'Kim', 'sol' => 'Sam W', 'x' => ''], 'jarfalla' => ['batteri' => 'Roberto', 'laddbox' => 'Elin', 'sol' => 'Sam L', 'x' => '']];
$legacySupport = ['malmo' => ['batteri' => 'Kim', 'laddbox' => 'Kim', 'sol' => 'SamW', 'x' => ''], 'jarfalla' => ['batteri' => 'Roberto', 'laddbox' => 'Elin', 'sol' => 'SamL', 'x' => '']];
$legacyCrew = ['sol' => ['Isaq', 'Abol'], 'laddbox' => ['Kim'], 'batteri' => ['Kim'], 'x' => []];

$check = static function (string $label) use ($legacyLead, $legacyNoEmail, $legacyWebshop, $legacyQuote, $legacySupport, $legacyCrew): void {
    foreach ($legacyLead as $o => $want) {
        ur(jeab_lead_owner($o) === $want, "$label: jeab_lead_owner($o)");
    }
    foreach ($legacyNoEmail as $c => $rows) {
        foreach ($rows as $o => $want) {
            ur(jeab_no_email_owner($o, $c) === $want, "$label: jeab_no_email_owner($o,$c) = $want");
            ur(jeab_webshop_assignee($c, $o) === ($legacyWebshop[$c][$o] ?? ''), "$label: jeab_webshop_assignee($c,$o)");
            if (isset($legacyQuote[$c][$o])) {
                ur(jeab_lead_quote_owner_name($o, $c) === $legacyQuote[$c][$o], "$label: jeab_lead_quote_owner_name($o,$c)");
                ur(jeab_inkorg_support_owner_id($o, $c) === $legacySupport[$c][$o], "$label: jeab_inkorg_support_owner_id($o,$c)");
            }
        }
    }
    foreach ($legacyCrew as $k => $want) {
        ur(jeab_malmo_job_crew($k) === $want, "$label: jeab_malmo_job_crew($k)");
    }
    ur(jeab_user_responsibility_for('sol', 'skane', 'owner') === '', "$label: solceller Skåne har ingen ägarregel");
    ur(jeab_user_responsibility_for('solpaneler', 'Järfälla') === 'SamL', "$label: alias solpaneler/Järfälla");
    ur(jeab_user_responsibility_people_for('sol', 'stockholm', 'install') === ['Niman'], "$label: Niman installerar sol");
    ur(jeab_user_responsibility_for('laddbox', 'malmo', 'install') === 'Kim' && jeab_user_responsibility_for('batteri', 'jarfalla', 'install') === 'Roberto', "$label: installation laddbox/batteri");
    ur(jeab_user_responsibility_for('batteri', 'stockholm', 'sender') === 'Roberto' && jeab_user_responsibility_for('sol', 'skane', 'sender') === '', "$label: avsändare för paket H");
};

// 1) Utan SQL: seeden gäller.
$check('utan SQL');

// 2) SQLite: tom tabell seedas, samma svar.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_RESP_PDO'] = $pdo;
jeab_user_responsibility_reset_cache();
$check('SQLite seed');
$n = (int) $pdo->query('SELECT COUNT(*) FROM user_responsibility')->fetchColumn();
ur($n === count(jeab_user_responsibility_seed()), 'seeden skrev en rad per person');
ur(str_contains(jeab_user_responsibility_get('SamL')['note'], 'Solceller'), 'fritext läses');

// 3) Skriv: Elin lämnar laddbox, SamL tar den. Regler normaliseras.
ur(jeab_user_responsibility_save('Elin', 'Bara installation', [['product' => 'elbilsladdare', 'region' => 'Järfälla', 'roles' => ['install', 'bogus']]], 'test'), 'spara Elin');
ur(jeab_user_responsibility_save('SamL', 'Sol + laddbox', [
    ['product' => 'sol', 'region' => 'stockholm', 'roles' => ['owner', 'quote', 'sender']],
    ['product' => 'laddbox', 'region' => 'stockholm', 'roles' => ['owner']],
], 'test'), 'spara SamL');
jeab_user_responsibility_reset_cache();
ur(jeab_user_responsibility_get('Elin')['rules'] === [['product' => 'laddbox', 'region' => 'stockholm', 'roles' => ['install']]], 'okänd roll tas bort, alias normaliseras');
ur(jeab_lead_owner('laddbox') === ['SamL', 'Sam L'], 'ändrad regel styr påminnelseägaren');
ur(jeab_user_responsibility_for('laddbox', 'stockholm', 'install') === 'Elin', 'installationen ligger kvar hos Elin');
ur(jeab_user_responsibility_get('Elin')['note'] === 'Bara installation', 'fritext skrivs');
ur((int) $pdo->query('SELECT COUNT(*) FROM user_responsibility')->fetchColumn() === $n, 'ingen omseed efter redigering');
ur(!is_file($tmp . '/user-responsibility.json'), 'ingen JSON-fil');

// 4) Sidan: namnet en gång per person, ingen hel kod.
$html = jeab_users_responsibility_html();
ur(substr_count($html, '<h2>Sam L</h2>') === 1, 'Sam L visas en gång');
ur(str_contains($html, 'name="resp[laddbox|stockholm][]"'), 'formuläret har produkt/region-rutor');
echo "klart\n";
