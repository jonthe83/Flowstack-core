<?php

declare(strict_types=1);

/**
 * Nybodavägen 15 Steningehöjden: reservsökning mot Sigtuna, båda ägarna,
 * beteckning och offertpris in på ordern. Knappen blockerar med fältnamn
 * när något saknas. Inget personnummer skrivs ut. Inget Fortnox-anrop.
 */

$tmp = sys_get_temp_dir() . '/jeab-bekrafta-carry-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
$_GET['co'] = 'jarfalla';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/address_lookup.php';

function bk_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function bk_a(): string
{
    return implode('', ['19', '61', '01', '01', '11', '11']);
}

function bk_b(): string
{
    return implode('', ['19', '56', '02', '02', '11', '11']);
}

function bk_secret(string $hay, string $label): void
{
    $bad = str_contains($hay, bk_a()) || str_contains($hay, bk_b());
    $normA = function_exists('jeab_pnr_normalize') ? jeab_pnr_normalize(bk_a()) : '';
    $normB = function_exists('jeab_pnr_normalize') ? jeab_pnr_normalize(bk_b()) : '';
    if ($normA !== '') {
        $bad = $bad || str_contains($hay, $normA);
    }
    if ($normB !== '') {
        $bad = $bad || str_contains($hay, $normB);
    }
    $bad = $bad || preg_match('/(?<!\d)(?:\d{6}|\d{8})[-+]?\d{4}(?!\d)/', $hay) === 1;
    bk_assert(!$bad, $label);
}

$GLOBALS['BK_A'] = bk_a();
$GLOBALS['BK_B'] = bk_b();
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
        $GLOBALS['BK_CALLS'][] = (string) $addressText;
        $t = (string) $addressText;
        if (!str_contains($t, 'Nybodavägen') && !str_contains($t, 'Tunnvägen')) {
            return false;
        }
        $full = str_contains($t, 'Sigtuna');
        $thin = str_contains($t, 'Tunnvägen') && !$full;
        if ($thin) {
            $this->data = [[
                'matchedAddress' => 'Tunnvägen 15, 195 65 Steningehöjden',
                'postnummer' => '19565',
                'postort' => 'Steningehöjden',
                'agare' => ['namn' => 'Ulf Exempel', 'personnummer' => (string) ($GLOBALS['BK_B'] ?? ''), 'andel' => '1/2'],
            ]];
            return true;
        }
        if (!$full) {
            $this->data = [
                'addressSuggestions' => [
                    ['address' => 'Nybodavägen 15, 123 45 Annanstad'],
                    ['address' => 'Nybodavägen 15, 111 22 Annanort'],
                ],
            ];
            return true;
        }
        $street = str_contains($t, 'Tunnvägen') ? 'Tunnvägen 15' : 'Nybodavägen 15';
        $this->data = [[
            'fastighetsbeteckning' => 'Sigtuna Exemplet 5:1',
            'matchedAddress' => $street . ', 195 65 Steningehöjden',
            'postnummer' => '19565',
            'postort' => 'Steningehöjden',
            'owners' => [
                ['name' => 'Ylva Exempel', 'personnummer' => (string) ($GLOBALS['BK_A'] ?? ''), 'address' => $street . ', 195 65 Steningehöjden', 'andel' => '1/2'],
                ['name' => 'Ulf Exempel', 'personnummer' => (string) ($GLOBALS['BK_B'] ?? ''), 'address' => $street . ', 195 65 Steningehöjden', 'andel' => '1/2'],
            ],
        ]];
        return true;
    }
}
PHP);
$GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES'] = [$rotDir . '/data_scraping.php'];
$GLOBALS['BK_CALLS'] = [];
$GLOBALS['_JEAB_LM_CACHE_BYPASS'] = true;
jeab_ordrar_external_live(true);

bk_assert(jeab_rot_postal_municipality('Steningehöjden') === 'Sigtuna', 'Steningehöjden är postort i Sigtuna');
bk_assert(jeab_rot_municipality_query('Nybodavägen 15 Steningehöjden') === 'Nybodavägen 15 Sigtuna', 'reservsökningen är kommunen');
$queries = jeab_rot_lookup_queries('Nybodavägen 15 Steningehöjden');
bk_assert($queries === ['Nybodavägen 15 Steningehöjden', 'Nybodavägen 15, Märsta', 'Nybodavägen 15 Sigtuna', 'Nybodavägen 15, Sigtuna'], 'sökningen är orten, Märsta, Sigtuna och Sigtuna med komma');

$store = new JeabJsonStore($tmp . '/store.json');
$GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
$undo = new PDO('sqlite::memory:');
$undo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$undo->exec('CREATE TABLE settings ("key" TEXT PRIMARY KEY, value TEXT NOT NULL)');
$GLOBALS['_JEAB_FASTIGHET_UNDO_PDO'] = $undo;

$lead = jeab_create_reminder($store, 'Ulf Exempel', '2026-10-23 00:00:00', null, null, [
    'customer' => 'Ulf Exempel',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
    'address' => 'Nybodavägen 15 Steningehöjden',
    'ort' => 'Steningehöjden',
    'note' => 'Offertpris: 48 000 kr',
]);
$id = (int) ($lead['id'] ?? 0);
bk_assert($id > 0, 'påminnelsen finns');
jeab_purchase_put([
    'reminder_id' => $id,
    'kind' => 'batteri',
    'company' => 'jarfalla',
    'name' => 'Ulf Exempel',
    'address' => 'Nybodavägen 15 Steningehöjden',
    'ort' => 'Steningehöjden',
    'huvudsokande' => 'Ulf Exempel',
    'personnummer' => bk_b(),
    'owners' => [[
        'name' => 'Ulf Exempel',
        'personnummer' => bk_b(),
        'address' => 'Nybodavägen 15 Steningehöjden',
        'andel' => '1/2',
    ]],
    'medsokande' => [],
    'fastighetsbeteckning' => '',
    'pris' => 0,
    'kundpris' => 0,
]);

$GLOBALS['BK_CALLS'] = [];
$reminder = jeab_get_reminder($store, $id);
$prepared = jeab_ordrar_bekrafta_prepare(jeab_purchase_get($id), is_array($reminder) ? $reminder : null);
$row = $prepared['row'];
$calls = is_array($GLOBALS['BK_CALLS'] ?? null) ? $GLOBALS['BK_CALLS'] : [];
bk_assert(count($calls) === 3, 'orten, Märsta och kommunen söks, fick ' . count($calls) . ' ' . implode(' | ', $calls));
bk_assert(str_contains((string) ($calls[0] ?? ''), 'Steningehöjden') && !str_contains((string) ($calls[0] ?? ''), 'Sigtuna'), 'första sökningen behåller postorten');
bk_assert(($calls[1] ?? '') === 'Nybodavägen 15, Märsta', 'andra sökningen är gatan mot Märsta');
bk_assert(($calls[2] ?? '') === 'Nybodavägen 15 Sigtuna', 'tredje sökningen är gatan mot Sigtuna');
bk_assert(($row['fastighetsbeteckning'] ?? '') === 'SIGTUNA EXEMPLET 5:1', 'order får fastighetsbeteckningen');
bk_assert((int) ($row['pris'] ?? 0) === 48000 && (int) ($row['kundpris'] ?? 0) === 48000, 'offertpriset följer med till ordern');
$names = [];
$ulfKept = false;
foreach (jeab_ordrar_people_list($row['owners'] ?? [], 8) as $owner) {
    $names[] = (string) ($owner['name'] ?? '');
    if (($owner['name'] ?? '') === 'Ulf Exempel' && jeab_ordrar_format_pnr((string) ($owner['personnummer'] ?? '')) === jeab_ordrar_format_pnr(bk_b())) {
        $ulfKept = true;
    }
}
sort($names);
$medNames = [];
foreach (jeab_ordrar_people_list($row['medsokande'] ?? [], 8) as $owner) {
    $medNames[] = (string) ($owner['name'] ?? '');
}
bk_assert($names === ['Ulf Exempel', 'Ylva Exempel'], 'båda ägarna ligger på ordern, fick ' . implode(', ', $names));
bk_assert($medNames === ['Ylva Exempel'], 'den andra ägaren är medsökande');
bk_assert($ulfKept, 'sparat personnummer på första ägaren töms inte');
bk_assert(jeab_ordrar_bekrafta_block_message($row) === '', 'komplett batteriorder blockerar inte');

unset($GLOBALS['_JEAB_ORDRAR_SUGGEST']);
$form = jeab_ordrar_v3_bekrafta_form($row, 'jarfalla', $id, is_array($reminder) ? $reminder : null);
$html = (string) ($form['html'] ?? '');
bk_assert(!str_contains($html, 'data-v3-missing>'), 'ingen saknas-varning när uppgifterna finns');
bk_assert(!str_contains($html, 'data-v3-missing-fast'), 'fastighetsbeteckningen är ifylld');
bk_assert(!str_contains($html, 'data-v3-missing-pnr'), 'personnummer är ifyllt');
bk_assert(!str_contains($html, 'data-v3-missing-pris'), 'priset är ifyllt');
bk_assert(str_contains($html, 'SIGTUNA EXEMPLET 5:1'), 'formuläret visar beteckningen');
bk_assert(str_contains($html, 'Ylva Exempel') && str_contains($html, 'Ulf Exempel'), 'formuläret visar båda ägarna');
bk_assert(str_contains($html, '48'), 'formuläret visar priset');
$page = jeab_ordrar_v3_bekrafta_html($row, 'jarfalla', $id, ['state' => 'todo', 'n' => 1], $store, is_array($reminder) ? $reminder : null);
bk_assert(str_contains($page, '>Skapa order<'), 'knappen finns när ordern är komplett');
bk_assert(preg_match('/<button[^>]*data-v3-primary="bekrafta"(?![^>]*disabled)[^>]*>Skapa order</', $page) === 1, 'knappen är aktiv');

$kept = jeab_purchase_get($id);
bk_assert(($kept['fastighetsbeteckning'] ?? '') === 'SIGTUNA EXEMPLET 5:1', 'beteckningen ligger kvar efter visningen');
bk_assert((int) ($kept['pris'] ?? 0) === 48000, 'priset ligger kvar');

$ladder = jeab_create_reminder($store, 'Ladder', '2026-10-23 00:00:00', null, null, [
    'customer' => 'Ladder',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
    'address' => 'Annanvägen 2',
    'note' => function_exists('jeab_offer_batteri_neo_price_paragraph') ? jeab_offer_batteri_neo_price_paragraph() : '7,5 kWh 39 500 kr, 15 kWh 55 500 kr',
]);
$ladderId = (int) ($ladder['id'] ?? 0);
jeab_purchase_put([
    'reminder_id' => $ladderId,
    'kind' => 'batteri',
    'company' => 'jarfalla',
    'name' => 'Ladder',
    'address' => 'Annanvägen 2',
    'huvudsokande' => '',
    'fastighetsbeteckning' => '',
    'pris' => 0,
    'kundpris' => 0,
    'owners' => [],
    'medsokande' => [],
]);
$ladderRow = jeab_purchase_get($ladderId);
$ladderReminder = jeab_get_reminder($store, $ladderId);
$afterLadder = jeab_ordrar_bekrafta_apply_carry($ladderRow, ['lookup_empty' => true, 'owners' => [], 'fastighetsbeteckning' => ''], is_array($ladderReminder) ? $ladderReminder : null);
bk_assert((int) ($afterLadder['pris'] ?? 0) === 0, 'en prislista utan ett valt belopp sätter inget pris');
$ladderMsg = jeab_ordrar_bekrafta_block_message($afterLadder);
bk_assert(str_contains($ladderMsg, 'fastighetsbeteckning') && str_contains($ladderMsg, 'personnummer') && str_contains($ladderMsg, 'pris'), 'meddelandet nämner beteckning, personnummer och pris');
bk_assert(str_starts_with($ladderMsg, 'Saknas för Fortnox-order:'), 'meddelandet är samma mening som under knappen');

$offer = jeab_ordrar_price_candidate(['kind' => 'batteri', 'kalkyl' => ['att_betala' => 61000, 'sigen_typ' => 'neo']], ['offertpris' => 48000]);
bk_assert((int) ($offer['amount'] ?? 0) === 48000 && str_starts_with((string) ($offer['source'] ?? ''), 'paminnelse'), 'offertpris vinner över kalkyl');
$neo = jeab_ordrar_price_candidate(['kind' => 'batteri', 'kwh' => '15'], []);
bk_assert((int) ($neo['amount'] ?? 0) === 55500 && ($neo['source'] ?? '') === 'neo', 'känd batteristorlek tar NEO-priset när offert saknas');

$GLOBALS['BK_CALLS'] = [];
$thin = jeab_rot_lookup_address('Tunnvägen 15 Steningehöjden');
$thinCalls = is_array($GLOBALS['BK_CALLS'] ?? null) ? $GLOBALS['BK_CALLS'] : [];
$thinNames = [];
foreach (is_array($thin['owners'] ?? null) ? $thin['owners'] : [] as $owner) {
    if (is_array($owner)) {
        $thinNames[] = (string) ($owner['name'] ?? '');
    }
}
sort($thinNames);
bk_assert(count($thinCalls) === 3, 'tunn träff söks om mot Märsta och kommunen, fick ' . implode(' | ', $thinCalls));
bk_assert(($thin['fastighetsbeteckning'] ?? '') === 'SIGTUNA EXEMPLET 5:1', 'tunn träff fylls med beteckning');
bk_assert($thinNames === ['Ulf Exempel', 'Ylva Exempel'], 'tunn träff behåller båda ägarna, fick ' . implode(', ', $thinNames) . ' antal ' . count($thinNames));

$blockers = jeab_ordrar_bekrafta_blockers($store, ['order_id' => $ladderId, 'dry' => 0]);
$encoded = json_encode($blockers, JSON_UNESCAPED_UNICODE);
bk_assert(is_string($encoded), 'blockerar-svaret kan kodas');
bk_secret((string) $encoded, 'blockerar-svaret innehåller inget personnummer');
bk_assert(($blockers['dry'] ?? 0) === 1 && ($blockers['written'] ?? 1) === 0, 'blockerar-hooken skriver inget');
bk_assert((int) $GLOBALS['JEAB_FORTNOX_WRITES'] === 0, 'ingen Fortnox-skrivning');

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
bk_secret($log, 'felloggen innehåller inget personnummer');
