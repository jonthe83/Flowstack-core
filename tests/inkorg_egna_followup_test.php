<?php

declare(strict_types=1);

/**
 * Uppföljning #995: Egna-raden följer med vid listbyte, Egna Historik räknar bara
 * personens egna (inte hela otilldelade arkivet), och vyraden är de fem mapparna
 * från klassningen (#601). Ingen DDL i data/, inget utskick.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-ixegfu-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla'];

$fail = 0;
$ok = static function (bool $c, string $msg) use (&$fail): void {
    if (!$c) {
        $fail++;
        fwrite(STDERR, "FAIL: $msg\n");
    }
};

// (2) Egna Historik: bara personens egna.
$pdo = jeab_pdo_connect_sqlite();
$ok(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-läge');
$store = jeab_inkorg_pdo_store();
$ok($store instanceof JeabPdoInkorgStore, 'SQL-lager');
$rows = [['u1', ''], ['u2', ''], ['u3', ''], ['m1', 'SamL'], ['m2', 'saml'], ['r1', 'Roberto']];
foreach ($rows as [$id, $as]) {
    $store->saveListRow([
        'id' => $id, 'company' => 'jarfalla', 'status' => 'closed', 'deleted' => false, 'assignee' => $as,
        'updated_at' => '2024-05-01T10:00:00+00:00', 'customer_email' => $id . '@exempel.test',
        'customer_name' => $id, 'labels' => ['type' => 'Support'], 'tags' => [],
    ]);
}
jeab_hs_import_store_ensure($pdo);
jeab_hs_import_ensure_list_page($pdo);
$ins = $pdo->prepare('INSERT INTO jeab_hs_import_index (id, payload, list_company, list_status, list_at) VALUES (?, ?, ?, ?, ?)');
$ins->execute(['hsi-a', '{"id":"hsi-a","company":"jarfalla","status":"closed","assignee":""}', 'jarfalla', 'closed', '2024-01-01T00:00:00+00:00']);
$ins->execute(['hsi-b', '{"id":"hsi-b","company":"jarfalla","status":"closed","assignee":"SamL"}', 'jarfalla', 'closed', '2024-01-02T00:00:00+00:00']);
$all = jeab_inkorg_historik_total_noddl('jarfalla');
$mine = jeab_inkorg_historik_total_noddl('jarfalla', 'me', 'SamL');
$others = jeab_inkorg_historik_total_noddl('jarfalla', 'others', 'SamL');
$ok($all === 8, 'Alla Historik räknar allt, fick ' . $all);
$ok($mine === 3, 'Egna Historik räknar bara mina (2 i listan + 1 i arkivet), fick ' . $mine);
$ok($others === 1, 'Andras Historik oförändrad, fick ' . $others);
$ok(jeab_inkorg_historik_total_noddl('jarfalla', 'me', '') === 0, 'okänd person räknar inget med personfilter');

// (2) Raden visas när personen är okänd, även efter listbyte i JS.
$page = (string) file_get_contents($root . '/src/pages/inkorg.php');
$ok(str_contains($page, 'data-ix-egna-unknown="<?= $ixEgnaUnknown ? \'1\' : \'0\' ?>"'), 'raden bär om personen är okänd');
$ok(str_contains($page, "<?= (\$scope === 'egna' && \$ixEgnaUnknown) ? '' : ' hidden' ?>"), 'raden renderas alltid, dold utanför Egna');
$mark = strpos($page, 'function ixMarkScope(scope) {');
$ok($mark !== false && str_contains(substr($page, $mark, 300), 'ixMarkEgnaFallback(scope);'), 'varje scopebyte visar eller döljer raden');
$ok(str_contains($page, "if (scope === 'egna' && unknown) { el.removeAttribute('hidden'); } else { el.setAttribute('hidden', ''); }"), 'raden följer Egna och okänd person');

$ok(str_contains($page, "document.querySelectorAll('#ix a.smart-tile[data-ix-view]')") && str_contains($page, "t.searchParams.set('scope', cur);"), 'flikarna behåller Egna efter listbyte');
$ok(str_contains($page, 'if (ixPrevScope && ixPrevScope !== scope && !ixCountsAsked) { ixCountsFor(url); }'), 'scopebyte hämtar alla flikars tal');
$cf = strpos($page, 'function ixCountsFor(url) {');
$ok($cf !== false && !preg_match('/method\s*:\s*[\'"]POST/i', substr($page, $cf, 1400)), 'talhämtningen är en GET');

// (3) Vyraden är de fem mapparna (#601), inte sparade personvyer.
$ids = array_column(jeab_inkorg_fast_scope_menu('allas'), 'id');
$ok($ids === ['v-inkorg', 'v-service', 'v-information', 'v-alla', 'v-historik'], 'vyraden är klassningens mappar');

if ($fail) {
    exit(1);
}
echo "OK inkorg_egna_followup\n";
