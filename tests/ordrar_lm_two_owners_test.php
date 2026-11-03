<?php

declare(strict_types=1);

/**
 * Flera lagfarter/tomträtt blir alla ägare, var och en med personnummerfält.
 * Ett Lantmäteriet-anrop per order. Cache på fastighet kan ogiltigförklaras.
 * Hook ordrar.lm_refetch. Inget Fortnox-anrop. Inget personnummer i svar eller logg.
 */

$tmp = sys_get_temp_dir() . '/jeab-lm-two-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa temp\n");
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

function two_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function two_a(): string
{
    return implode('', ['19', '80', '01', '01', '12', '34']);
}

function two_b(): string
{
    return implode('', ['19', '75', '02', '02', '43', '21']);
}

function two_norm(string $digits): string
{
    return jeab_pnr_normalize($digits);
}

$fortnoxWrites = 0;
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function () use (&$fortnoxWrites): array {
    $fortnoxWrites++;
    return [];
};
$GLOBALS['_JEAB_FORTNOX_CREATE'] = static function (array $row) use (&$fortnoxWrites): array {
    $fortnoxWrites++;
    return $row;
};

$rawList = [
    [
        'fastighetsbeteckning' => 'Testköping Exemplet 9:9',
        'matchedAddress' => 'Exempelvägen 2, 135 00 Testköping',
        'postnummer' => '13500',
        'postort' => 'Testköping',
        'agare' => ['namn' => 'Ada Exempel', 'andel' => '1/2'],
    ],
    [
        'lagfart' => [[
            'inskrivningsdag' => '2021-03-04',
            'andel' => ['taljare' => '1', 'namnare' => '2'],
            'agare' => ['förnamn' => 'Bo', 'efternamn' => 'Exempel', 'personnummer' => two_b()],
        ]],
        'tomtratt' => [[
            'inskrivningsdag' => '2019-05-01',
            'andel' => ['täljare' => '1', 'nämnare' => '2'],
            'agare' => ['namn' => 'Ada Exempel', 'personidentitet' => two_a()],
        ]],
    ],
];
$norm = jeab_rot_normalize_lookup($rawList);
$byName = [];
foreach (is_array($norm['owners'] ?? null) ? $norm['owners'] : [] as $owner) {
    if (is_array($owner)) {
        $byName[(string) ($owner['name'] ?? '')] = $owner;
    }
}
two_assert(($norm['fastighetsbeteckning'] ?? '') === 'TESTKÖPING EXEMPLET 9:9', 'listan av lagfarter behåller beteckningen');
two_assert(isset($byName['Ada Exempel'], $byName['Bo Exempel']), 'båda ägarna läses, inte bara den första lagfarten');
two_assert(array_key_exists('personnummer', $byName['Ada Exempel']) && array_key_exists('personnummer', $byName['Bo Exempel']), 'varje ägare har ett personnummerfält');
two_assert(($byName['Ada Exempel']['personnummer'] ?? '') === two_norm(two_a()), 'tomträttens personnummer följer med');
two_assert(($byName['Bo Exempel']['personnummer'] ?? '') === two_norm(two_b()), 'lagfartens personnummer följer med');
two_assert(($byName['Ada Exempel']['andel'] ?? '') === '1/2' && ($byName['Bo Exempel']['andel'] ?? '') === '1/2', 'andelar läses');

$rotDir = $tmp . '/rot';
@mkdir($rotDir, 0775, true);
$digitsA = two_a();
$digitsB = two_b();
file_put_contents($rotDir . '/data_scraping.php', <<<PHP
<?php
class LantmaterietAPI {
    public \$data = [];
    public function search(\$addressText, \$uuid = '') {
        \$t = (string) \$addressText;
        \$fel = stripos(\$t, 'Felvägen') !== false;
        if (stripos(\$t, 'Exempelvägen') === false && !\$fel) {
            return false;
        }
        \$line = \$fel ? 'Felvägen 4, 135 00 Testköping' : 'Exempelvägen 2, 135 00 Testköping';
        \$this->data = [
            [
                'fastighetsbeteckning' => 'Testköping Exemplet 9:9',
                'matchedAddress' => \$line,
                'postnummer' => '13500',
                'postort' => 'Testköping',
                'agare' => ['namn' => 'Ada Exempel', 'andel' => '1/2'],
            ],
            [
                'lagfart' => [[
                    'inskrivningsdag' => '2021-03-04',
                    'andel' => ['taljare' => '1', 'namnare' => '2'],
                    'agare' => ['förnamn' => 'Bo', 'efternamn' => 'Exempel', 'personnummer' => '{$digitsB}'],
                ]],
                'tomtratt' => [[
                    'inskrivningsdag' => '2019-05-01',
                    'andel' => ['täljare' => '1', 'nämnare' => '2'],
                    'agare' => ['namn' => 'Ada Exempel', 'personidentitet' => '{$digitsA}'],
                ]],
            ],
        ];
        return true;
    }
}
PHP);
$GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES'] = [$rotDir . '/data_scraping.php'];
$GLOBALS['_JEAB_LM_SEARCH_CALLS'] = 0;
jeab_ordrar_external_live(true);

$store = new JeabJsonStore($tmp . '/store.json');
$GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
$lead = jeab_create_reminder($store, 'Ada Exempel', '2026-10-20 00:00:00', null, null, [
    'customer' => 'Ada Exempel',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
    'address' => 'Exempelvägen 2, Testköping',
    'ort' => 'Testköping',
]);
$leadId = (int) ($lead['id'] ?? 0);
two_assert($leadId > 0, 'påminnelsen skapades');
$created = jeab_purchase_start($store, $leadId, '');
$owners = [];
foreach (jeab_ordrar_people_list($created['owners'] ?? [], 8) as $owner) {
    $owners[(string) ($owner['name'] ?? '')] = $owner;
}
two_assert((int) ($GLOBALS['_JEAB_LM_SEARCH_CALLS'] ?? 0) === 1, 'en ny order gör ett Lantmäteriet-anrop');
two_assert(isset($owners['Ada Exempel'], $owners['Bo Exempel']), 'ordern får båda ägarna');
two_assert(($owners['Ada Exempel']['personnummer'] ?? '') === two_norm(two_a()), 'första ägarens personnummer är ifyllt');
two_assert(($owners['Bo Exempel']['personnummer'] ?? '') === two_norm(two_b()), 'andra ägarens personnummer är ifyllt');
two_assert($fortnoxWrites === 0, 'uppslaget skriver inte till Fortnox');

$again = jeab_ordrar_address_lookup(jeab_ordrar_lookup_query($lead, $created));
two_assert((int) ($GLOBALS['_JEAB_LM_SEARCH_CALLS'] ?? 0) === 1, 'samma fastighet läses från cachen');
two_assert(count($again['owners'] ?? []) === 2, 'cachen har båda ägarna');
$lead2 = jeab_create_reminder($store, 'Bo Exempel', '2026-10-21 00:00:00', null, null, [
    'customer' => 'Bo Exempel',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
    'address' => 'Exempelvägen 2, Testköping',
    'ort' => 'Testköping',
]);
$second = jeab_purchase_start($store, (int) ($lead2['id'] ?? 0), '');
two_assert((int) ($GLOBALS['_JEAB_LM_SEARCH_CALLS'] ?? 0) === 1, 'nästa order på samma fastighet anropar inte igen');
two_assert(count(jeab_ordrar_people_list($second['owners'] ?? [], 8)) === 2, 'cachen fyller båda ägarna på nästa order');

$stale = jeab_purchase_put([
    'reminder_id' => 880512,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Caj Exempel',
    'huvudsokande' => 'Caj Exempel',
    'address' => 'Felvägen 4, Testköping',
    'ort' => 'Testköping',
    'personnummer' => '',
    'fastighetsbeteckning' => '',
    'owners' => [[
        'name' => 'Ada Exempel',
        'personnummer' => '',
        'address' => 'Felvägen 4, Testköping',
        'andel' => '',
        'lagfart' => '',
    ]],
]);
$callsBefore = (int) ($GLOBALS['_JEAB_LM_SEARCH_CALLS'] ?? 0);
$dry = jeab_ordrar_lm_refetch($store, ['order_id' => 880512, 'dry' => 1]);
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
two_assert(($dry['ok'] ?? false) === true && ($dry['dry'] ?? 0) === 1, 'dry=1 är standard');
two_assert(($dry['would_refetch'] ?? 0) === 1 && ($dry['calls'] ?? 1) === 0, 'dry=1 visar att omhämt skulle ske och anropar inte');
two_assert(($dry['reason'] ?? '') === 'saknar_cache', 'orsaken är att cachen saknas för den adressen');
two_assert((int) ($GLOBALS['_JEAB_LM_SEARCH_CALLS'] ?? 0) === $callsBefore, 'dry=1 gör inget anrop');
two_assert(is_string($dryJson) && !str_contains($dryJson, two_a()) && !str_contains($dryJson, two_b()) && !str_contains($dryJson, two_norm(two_a())) && !str_contains($dryJson, two_norm(two_b())), 'dry=1-svaret saknar personnummer');

$denied = jeab_ordrar_lm_refetch($store, ['order_id' => 880512, 'dry' => 0, 'confirm' => 'nej']);
two_assert(($denied['ok'] ?? true) === false && ($denied['status'] ?? 0) === 422, 'dry=0 utan confirm nekas');
two_assert((int) ($GLOBALS['_JEAB_LM_SEARCH_CALLS'] ?? 0) === $callsBefore, 'nekad körning anropar inte');
two_assert((jeab_purchase_get(880512)['owners'][0]['personnummer'] ?? 'x') === '', 'nekad körning skriver inget');

$done = jeab_ordrar_lm_refetch($store, ['order_id' => 880512, 'dry' => 0, 'confirm' => 'SAM']);
$doneJson = json_encode($done, JSON_UNESCAPED_UNICODE);
$fixed = jeab_purchase_get(880512);
$fixedOwners = [];
foreach (jeab_ordrar_people_list(is_array($fixed) ? ($fixed['owners'] ?? []) : [], 8) as $owner) {
    $fixedOwners[(string) ($owner['name'] ?? '')] = $owner;
}
two_assert(($done['ok'] ?? false) === true && ($done['calls'] ?? 0) === 1, 'dry=0 gör ett anrop');
two_assert((int) ($GLOBALS['_JEAB_LM_SEARCH_CALLS'] ?? 0) === $callsBefore + 1, 'räknaren ökade med ett');
two_assert(isset($fixedOwners['Ada Exempel'], $fixedOwners['Bo Exempel']), 'omhämtet sparar båda ägarna');
two_assert(($fixedOwners['Ada Exempel']['personnummer'] ?? '') === two_norm(two_a()) && ($fixedOwners['Bo Exempel']['personnummer'] ?? '') === two_norm(two_b()), 'omhämtet fyller personnummer på båda');
two_assert(is_string($doneJson) && !str_contains($doneJson, two_a()) && !str_contains($doneJson, two_b()) && !str_contains($doneJson, two_norm(two_a())) && !str_contains($doneJson, two_norm(two_b())), 'omhämt-svaret saknar personnummer');
two_assert($fortnoxWrites === 0, 'omhämtet skriver inte till Fortnox');

$log = (string) @file_get_contents($tmp . '/app-errors.jsonl');
two_assert($log === '' || (!str_contains($log, two_a()) && !str_contains($log, two_b())), 'felloggen saknar personnummer');

$after = jeab_ordrar_lm_refetch($store, ['order_id' => (int) ($created['reminder_id'] ?? 0), 'dry' => 1]);
two_assert(($after['would_refetch'] ?? 1) === 0 && ($after['reason'] ?? '') === 'nej', 'en komplett cache behöver inget omhämt');
two_assert((int) ($GLOBALS['_JEAB_LM_SEARCH_CALLS'] ?? 0) === $callsBefore + 1, 'komplett cache anropar inte igen');

$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
two_assert(str_contains($hookSrc, "=== 'ordrar.lm_refetch'"), 'hook.php tar ordrar.lm_refetch');

$unauth = jeab_ordrar_lm_refetch_dispatch($store, ['order_id' => 880512, 'dry' => 1]);
two_assert(($unauth['status'] ?? 0) === 401, 'hooken kräver token');

$cachePath = jeab_rot_lm_cache_path();
$cacheDoc = json_decode((string) file_get_contents($cachePath), true);
two_assert(is_array($cacheDoc), 'cachen finns');
foreach ($cacheDoc['by_fastighet'] as $fastKey => $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $entry['owner_count'] = 1;
    $entry['pnr_count'] = 0;
    $entry['suspect'] = true;
    $entry['owners'] = [];
    $cacheDoc['by_fastighet'][$fastKey] = $entry;
}
file_put_contents($cachePath, json_encode($cacheDoc));
$callsNow = (int) ($GLOBALS['_JEAB_LM_SEARCH_CALLS'] ?? 0);
$suspectDry = jeab_ordrar_lm_refetch($store, ['order_id' => $leadId, 'dry' => 1]);
$suspectJson = json_encode($suspectDry, JSON_UNESCAPED_UNICODE);
two_assert(($suspectDry['would_refetch'] ?? 0) === 1 && ($suspectDry['cache_owner_count'] ?? -1) === 1, 'cache med fel ägarantal flaggas för omhämt');
two_assert(($suspectDry['reason'] ?? '') === 'saknar_personnummer' || ($suspectDry['reason'] ?? '') === 'agarantal', 'orsaken är ägarantal eller personnummer');
two_assert((int) ($GLOBALS['_JEAB_LM_SEARCH_CALLS'] ?? 0) === $callsNow, 'flaggad cache anropas inte i dry=1');
two_assert(is_string($suspectJson) && !str_contains($suspectJson, two_a()) && !str_contains($suspectJson, two_b()), 'flaggad cache lämnar inget personnummer i svaret');
$restored = jeab_ordrar_lm_refetch($store, ['order_id' => $leadId, 'dry' => 0, 'confirm' => 'SAM']);
two_assert(($restored['calls'] ?? 0) === 1 && (int) ($GLOBALS['_JEAB_LM_SEARCH_CALLS'] ?? 0) === $callsNow + 1, 'ogiltig cache hämtas om med ett anrop');
two_assert(count(jeab_ordrar_people_list(jeab_purchase_get($leadId)['owners'] ?? [], 8)) === 2, 'omhämtet återställer båda ägarna');

echo "OK  två ägare\n";
