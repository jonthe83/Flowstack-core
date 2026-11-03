<?php

declare(strict_types=1);

/**
 * Order 120: «Nybodavägen 15, Steningehöjden» (med komma).
 * Märsta före Sigtuna, pris från kalkyl skrivs när Bekräfta öppnas
 * även när sidvisningen har stängt av externa anrop, och
 * ordrar.fastighet_probe är torr och visar varje sökning.
 * Inget personnummer skrivs ut. Inget Fortnox-anrop.
 */

$tmp = sys_get_temp_dir() . '/jeab-ordrar-120-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
$_GET['p'] = 'ordrar';
$_GET['co'] = 'jarfalla';
$_SERVER['REQUEST_METHOD'] = 'GET';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/address_lookup.php';

function o120_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function o120_a(): string
{
    return implode('', ['19', '70', '03', '03', '22', '22']);
}

function o120_b(): string
{
    return implode('', ['19', '68', '04', '04', '22', '22']);
}

function o120_secret(string $hay, string $label): void
{
    $bad = str_contains($hay, o120_a()) || str_contains($hay, o120_b());
    $normA = function_exists('jeab_pnr_normalize') ? jeab_pnr_normalize(o120_a()) : '';
    $normB = function_exists('jeab_pnr_normalize') ? jeab_pnr_normalize(o120_b()) : '';
    if ($normA !== '') {
        $bad = $bad || str_contains($hay, $normA);
    }
    if ($normB !== '') {
        $bad = $bad || str_contains($hay, $normB);
    }
    $bad = $bad || preg_match('/(?<!\d)(?:\d{6}|\d{8})[-+]?\d{4}(?!\d)/', $hay) === 1;
    o120_assert(!$bad, $label);
}

$GLOBALS['O120_A'] = o120_a();
$GLOBALS['O120_B'] = o120_b();
$GLOBALS['O120_MARSTA_THIN'] = false;
$GLOBALS['JEAB_FORTNOX_WRITES'] = 0;
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (): array {
    $GLOBALS['JEAB_FORTNOX_WRITES'] = (int) $GLOBALS['JEAB_FORTNOX_WRITES'] + 1;
    return [];
};
$GLOBALS['_JEAB_FORTNOX_CREATE'] = static function (array $row): array {
    $GLOBALS['JEAB_FORTNOX_WRITES'] = (int) $GLOBALS['JEAB_FORTNOX_WRITES'] + 1;
    return $row;
};

$rotDir = $tmp . '/rot';
@mkdir($rotDir, 0775, true);
file_put_contents($rotDir . '/data_scraping.php', <<<'PHP'
<?php
class LantmaterietAPI {
    public $data = [];
    public function search($addressText, $uuid = '') {
        $GLOBALS['O120_CALLS'][] = (string) $addressText;
        $t = (string) $addressText;
        if (!str_contains($t, 'Nybodavägen')) {
            return false;
        }
        $full = static function (string $street) {
            return [[
                'fastighetsbeteckning' => 'Sigtuna Nyboda 1:15',
                'matchedAddress' => $street . ', 195 65 Märsta',
                'postnummer' => '19565',
                'postort' => 'Märsta',
                'owners' => [
                    ['name' => 'Ylva Exempel', 'personnummer' => (string) ($GLOBALS['O120_A'] ?? ''), 'address' => $street . ', 195 65 Märsta', 'andel' => '1/2'],
                    ['name' => 'Ulf Exempel', 'personnummer' => (string) ($GLOBALS['O120_B'] ?? ''), 'address' => $street . ', 195 65 Märsta', 'andel' => '1/2'],
                ],
            ]];
        };
        if (str_contains($t, 'Märsta')) {
            if (!empty($GLOBALS['O120_MARSTA_THIN'])) {
                $this->data = [[
                    'matchedAddress' => 'Nybodavägen 15, 195 65 Märsta',
                    'postnummer' => '19565',
                    'postort' => 'Märsta',
                    'agare' => ['namn' => 'Ulf Exempel', 'personnummer' => (string) ($GLOBALS['O120_B'] ?? ''), 'andel' => '1/2'],
                ]];
                return true;
            }
            $this->data = $full('Nybodavägen 15');
            return true;
        }
        if (str_contains($t, 'Sigtuna')) {
            $this->data = $full('Nybodavägen 15');
            return true;
        }
        $this->data = [
            'addressSuggestions' => [
                ['address' => 'Nybodavägen 15, 123 45 Annanstad'],
                ['address' => 'Nybodavägen 15, 195 65 Märsta'],
            ],
        ];
        return true;
    }
}
PHP);
$GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES'] = [$rotDir . '/data_scraping.php'];
$GLOBALS['O120_CALLS'] = [];
$GLOBALS['_JEAB_LM_CACHE_BYPASS'] = true;

$parts = jeab_rot_split_street_place('Nybodavägen 15, Steningehöjden');
o120_assert($parts['street'] === 'Nybodavägen 15' && $parts['place'] === 'Steningehöjden', 'komma efter husnumret ingår inte i gatan');
$normalized = jeab_rot_lookup_query('Nybodavägen 15, Steningehöjden');
o120_assert($normalized === 'Nybodavägen 15 Steningehöjden', 'uppslaget tar bort kommat före sökningen');
$queries = jeab_rot_lookup_queries($normalized);
o120_assert(in_array('Nybodavägen 15, Märsta', $queries, true), 'Märsta finns i söklistan');
o120_assert(in_array('Nybodavägen 15 Sigtuna', $queries, true) && in_array('Nybodavägen 15, Sigtuna', $queries, true), 'kommunen Sigtuna söks med och utan komma');
$marstaAt = array_search('Nybodavägen 15, Märsta', $queries, true);
$sigtunaAt = array_search('Nybodavägen 15 Sigtuna', $queries, true);
o120_assert($marstaAt !== false && $sigtunaAt !== false && $marstaAt < $sigtunaAt, 'Märsta söks före kommunen');

$store = new JeabJsonStore($tmp . '/store.json');
$GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
$undo = new PDO('sqlite::memory:');
$undo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$undo->exec('CREATE TABLE settings ("key" TEXT PRIMARY KEY, value TEXT NOT NULL)');
$GLOBALS['_JEAB_FASTIGHET_UNDO_PDO'] = $undo;
$purchasePdo = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchasePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchasePdo;
jeab_purchases_store_ensure($purchasePdo);

$lead = jeab_create_reminder($store, 'Ulf Exempel', '2026-10-23 00:00:00', null, null, [
    'customer' => 'Ulf Exempel',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
    'address' => 'Nybodavägen 15, Steningehöjden',
    'ort' => 'Steningehöjden',
]);
$id = (int) ($lead['id'] ?? 0);
o120_assert($id > 0, 'påminnelsen finns');
jeab_purchase_put([
    'reminder_id' => $id,
    'kind' => 'batteri',
    'company' => 'jarfalla',
    'name' => 'Ulf Exempel',
    'address' => 'Nybodavägen 15, Steningehöjden',
    'ort' => 'Steningehöjden',
    'huvudsokande' => 'Ulf Exempel',
    'personnummer' => o120_b(),
    'owners' => [[
        'name' => 'Ulf Exempel',
        'personnummer' => o120_b(),
        'address' => 'Nybodavägen 15, Steningehöjden',
        'andel' => '1/2',
    ]],
    'medsokande' => [],
    'fastighetsbeteckning' => '',
    'pris' => 0,
    'kundpris' => 0,
    'kalkyl' => ['sigen_typ' => 'tp2', 'att_betala' => 70000],
]);

$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
jeab_ordrar_external_live(false);
o120_assert(jeab_page_blocks_external(), 'en vanlig sidvisning blockerar externa anrop');
o120_assert(jeab_rot_external_blocked(), 'uppslaget är blockerat innan Bekräfta slår på det');

$GLOBALS['O120_CALLS'] = [];
$probe = jeab_ordrar_fastighet_probe($store, ['order_id' => $id, 'dry' => 0]);
$probeJson = json_encode($probe, JSON_UNESCAPED_UNICODE);
o120_assert(is_string($probeJson), 'proben kan kodas');
o120_secret((string) $probeJson, 'proben innehåller inget personnummer');
o120_assert(($probe['dry'] ?? 0) === 1 && ($probe['written'] ?? 1) === 0 && ($probe['action'] ?? '') === 'ordrar.fastighet_probe', 'proben skriver inget även med dry=0');
$stored = jeab_purchase_get($id);
o120_assert((int) ($stored['pris'] ?? 0) === 0 && trim((string) ($stored['fastighetsbeteckning'] ?? '')) === '', 'proben lämnar ordern');
$tried = [];
$marstaHit = false;
foreach (is_array($probe['queries'] ?? null) ? $probe['queries'] : [] as $step) {
    if (!is_array($step)) {
        continue;
    }
    $tried[] = (string) ($step['query'] ?? '');
    if (($step['query'] ?? '') === 'Nybodavägen 15, Märsta' && (int) ($step['fastighet'] ?? 0) === 1 && (int) ($step['owners'] ?? 0) === 2) {
        $marstaHit = true;
    }
}
o120_assert(in_array('Nybodavägen 15, Märsta', $tried, true), 'proben visar Märsta-sökningen, fick ' . implode(' | ', $tried));
o120_assert($marstaHit, 'proben visar beteckning och två ägare från Märsta');
o120_assert(($probe['fastighet'] ?? 0) === 1, 'proben säger att en beteckning kom tillbaka');

$GLOBALS['O120_CALLS'] = [];
jeab_ordrar_external_live(false);
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
unset($GLOBALS['_JEAB_ORDRAR_SUGGEST']);
$reminder = jeab_get_reminder($store, $id);
$html = jeab_ordrar_v3_html(jeab_purchase_get($id), 'jarfalla', $id, $store, null, null, is_array($reminder) ? $reminder : null);
$openCalls = is_array($GLOBALS['O120_CALLS'] ?? null) ? $GLOBALS['O120_CALLS'] : [];
o120_assert(in_array('Nybodavägen 15, Märsta', $openCalls, true), 'öppnad Bekräfta söker Märsta trots att sidan blockerar externa anrop, fick ' . implode(' | ', $openCalls));
$row = jeab_purchase_get($id);
o120_assert((int) ($row['pris'] ?? 0) === 70000 && (int) ($row['kundpris'] ?? 0) === 70000, 'kalkylens 70000 skrivs när Bekräfta öppnas');
o120_assert((string) ($row['fastighetsbeteckning'] ?? '') === jeab_ordrar_format_beteckning('Sigtuna Nyboda 1:15'), 'beteckningen skrivs från Märsta');
$names = [];
foreach (jeab_ordrar_people_list($row['owners'] ?? [], 8) as $owner) {
    $names[] = (string) ($owner['name'] ?? '');
}
sort($names);
o120_assert($names === ['Ulf Exempel', 'Ylva Exempel'], 'båda ägarna skrivs, fick ' . implode(', ', $names));
o120_assert(str_contains($html, '70') && str_contains($html, '000'), 'Att betala visar kalkylpriset');
o120_assert(str_contains($html, 'Ylva Exempel'), 'sidan visar den andra ägaren');
o120_assert(jeab_ordrar_bekrafta_block_message($row) === '', 'komplett order blockerar inte');
o120_assert(str_contains($html, '>Skapa order<'), 'knappen finns');
o120_assert(preg_match('/<button[^>]*data-v3-primary="bekrafta"(?![^>]*disabled)[^>]*>Skapa order</', $html) === 1, 'knappen är aktiv');

$GLOBALS['O120_MARSTA_THIN'] = true;
$GLOBALS['O120_CALLS'] = [];
unset($GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_SAPI']);
jeab_ordrar_external_live(true);
$GLOBALS['_JEAB_LM_CACHE_BYPASS'] = true;
$GLOBALS['_JEAB_LM_CACHE_GEN'] = (int) ($GLOBALS['_JEAB_LM_CACHE_GEN'] ?? 0) + 1;
$thin = jeab_rot_lookup_address('Nybodavägen 15, Steningehöjden');
$thinCalls = is_array($GLOBALS['O120_CALLS'] ?? null) ? $GLOBALS['O120_CALLS'] : [];
o120_assert(in_array('Nybodavägen 15, Märsta', $thinCalls, true) && in_array('Nybodavägen 15 Sigtuna', $thinCalls, true), 'tunn Märsta-träff går vidare till Sigtuna, fick ' . implode(' | ', $thinCalls));
o120_assert((string) ($thin['fastighetsbeteckning'] ?? '') === 'SIGTUNA NYBODA 1:15' || (string) ($thin['fastighetsbeteckning'] ?? '') === jeab_ordrar_format_beteckning('Sigtuna Nyboda 1:15'), 'tunn Märsta fylls med beteckning från kommunen');
$thinNames = [];
foreach (is_array($thin['owners'] ?? null) ? $thin['owners'] : [] as $owner) {
    if (is_array($owner)) {
        $thinNames[] = (string) ($owner['name'] ?? '');
    }
}
sort($thinNames);
o120_assert($thinNames === ['Ulf Exempel', 'Ylva Exempel'], 'tunn Märsta behåller båda ägarna, fick ' . implode(', ', $thinNames));
$traceJson = json_encode($GLOBALS['_JEAB_ROT_LOOKUP_TRACE'] ?? [], JSON_UNESCAPED_UNICODE);
o120_secret((string) $traceJson, 'spåret innehåller inget personnummer');

o120_assert((int) $GLOBALS['JEAB_FORTNOX_WRITES'] === 0, 'ingen Fortnox-skrivning');
$log = '';
foreach (glob($tmp . '/*.log') ?: [] as $path) {
    $log .= (string) file_get_contents($path);
}
if (is_dir($tmp . '/fellogg')) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp . '/fellogg', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getSize() < 200000) {
            $log .= (string) file_get_contents($file->getPathname());
        }
    }
}
o120_secret($log, 'felloggen innehåller inget personnummer');

echo "OK  order 120\n";
