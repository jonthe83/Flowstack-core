<?php

declare(strict_types=1);

/**
 * Ägare från en adresslista följer bara den valda beteckningen.
 * ordrar.set_owners behåller valda sparade ägare, utan Lantmäteriet.
 * Med query eller address: samma autocomplete som Manuell order, en exakt träff,
 * sedan jeab_rot_lookup_address(radens adress, radens objekt-id).
 * dry=1 är standard. dry=0 kräver confirm=SAM. Inget personnummer i svaret.
 */

$tmp = sys_get_temp_dir() . '/jeab-set-owners-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
$_ENV['JEAB_DISABLE_SQLITE'] = '1';
$GLOBALS['JEAB_FORTNOX_WRITES'] = 0;

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/address_lookup.php';

function so_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function so_pnr(string $tail): string
{
    return implode('', ['19', '70', '01', '01', $tail]);
}

function so_no_pnr(string $hay, string $label): void
{
    $bad = preg_match('/(?<!\d)(?:\d{6}|\d{8})[-+]?\d{4}(?!\d)/', $hay) === 1;
    foreach ([so_pnr('1111'), so_pnr('2222'), so_pnr('3333')] as $digits) {
        $norm = function_exists('jeab_pnr_normalize') ? jeab_pnr_normalize($digits) : $digits;
        $bad = $bad || str_contains($hay, $digits) || ($norm !== '' && str_contains($hay, $norm));
    }
    so_assert(!$bad, $label);
}

function so_row(string $fast, array $names, bool $withAddress = true): array
{
    $owners = [];
    $n = max(1, count($names));
    foreach ($names as $i => $name) {
        $owners[] = [
            'name' => $name,
            'andel' => '1/' . $n,
            'personnummer' => so_pnr(str_pad((string) (10 + $i), 4, '0', STR_PAD_LEFT)),
            'address' => 'Flottarestigen 6, 191 41 Sollentuna',
        ];
    }
    $row = [
        'owners' => $owners,
        'postnummer' => '19141',
        'postort' => 'Sollentuna',
    ];
    if ($withAddress) {
        $row['matchedAddress'] = 'Flottarestigen 6, 191 41 Sollentuna';
    }
    if ($fast !== '') {
        $row['fastighetsbeteckning'] = $fast;
    }

    return $row;
}

$rows = [so_row('Sollentuna Lertavlan 4', ['Ada En', 'Bo Två'])];
for ($i = 1; $i <= 6; $i++) {
    $rows[] = so_row('', ['Extra ' . $i]);
}
$merged = jeab_rot_merge_lookup_list($rows);
$mergedNames = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), $merged['owners'] ?? []);
so_assert(($merged['fastighetsbeteckning'] ?? '') === 'SOLLENTUNA LERTAVLAN 4', 'listan behåller Lertavlan 4');
so_assert($mergedNames === ['Ada En', 'Bo Två'], 'listan tar inte ägare från de andra raderna, fick ' . implode(', ', $mergedNames));

$picked = jeab_rot_pick_lookup($rows, 'Flottarestigen 6, 191 41 Sollentuna', '');
$pickedNames = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), $picked['norm']['owners'] ?? []);
so_assert(!empty($picked['accepted']), 'adressökningen väljer Lertavlan 4');
so_assert(($picked['norm']['fastighetsbeteckning'] ?? '') === 'SOLLENTUNA LERTAVLAN 4', 'valet är Lertavlan 4');
so_assert($pickedNames === ['Ada En', 'Bo Två'], 'valet har två ägare, fick ' . implode(', ', $pickedNames));

$nested = jeab_rot_normalize_lookup([
    'fastighetsbeteckning' => 'Sollentuna Lertavlan 4',
    'matchedAddress' => 'Flottarestigen 6, 191 41 Sollentuna',
    'agare' => [
        ['namn' => 'Ada En', 'andel' => '1/2'],
        ['namn' => 'Bo Två', 'andel' => '1/2'],
        [
            'fastighetsbeteckning' => 'Sollentuna Annan 9',
            'agare' => ['namn' => 'Cia Tre', 'andel' => '1/1'],
        ],
    ],
]);
$nestedNames = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), $nested['owners'] ?? []);
so_assert($nestedNames === ['Ada En', 'Bo Två'], 'nästlad annan fastighet lämnar inga ägare, fick ' . implode(', ', $nestedNames));

$store = jeab_store();
$GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
$purchasePdo = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchasePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchasePdo;
jeab_purchases_store_ensure($purchasePdo);
$undoPdo = new PDO('sqlite::memory:');
$undoPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$undoPdo->exec('CREATE TABLE settings ("key" TEXT PRIMARY KEY, value TEXT NOT NULL)');
$GLOBALS['_JEAB_FASTIGHET_UNDO_PDO'] = $undoPdo;

$ada = jeab_ordrar_format_pnr(so_pnr('1111'));
$bo = jeab_ordrar_format_pnr(so_pnr('2222'));
$cia = jeab_ordrar_format_pnr(so_pnr('3333'));
jeab_purchase_put([
    'reminder_id' => 880701,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Ada En',
    'huvudsokande' => 'Ada En',
    'personnummer' => $ada,
    'fastighetsbeteckning' => 'SOLLENTUNA LERTAVLAN 4',
    'address' => 'Flottarestigen 6, Sollentuna',
    'owners' => [
        ['name' => 'Ada En', 'personnummer' => $ada, 'address' => 'Flottarestigen 6', 'andel' => '1/2', 'lagfart' => ''],
        ['name' => 'Bo Två', 'personnummer' => $bo, 'address' => 'Flottarestigen 6', 'andel' => '1/2', 'lagfart' => ''],
        ['name' => 'Cia Tre', 'personnummer' => $cia, 'address' => 'Flottarestigen 6', 'andel' => '1/1', 'lagfart' => ''],
    ],
    'medsokande' => [],
    'pris' => 15000,
    'steps' => [],
]);

$GLOBALS['_JEAB_LM_SEARCH_CALLS'] = 0;
$denied = jeab_ordrar_set_owners_dispatch($store, [
    'order_id' => 880701,
    'keep' => [1],
    'dry' => 0,
    'confirm' => 'SAM',
]);
so_assert(($denied['ok'] ?? true) === false && (int) ($denied['status'] ?? 0) === 401, 'hooken kräver token');

$dry = jeab_ordrar_set_owners_run($store, ['order_id' => 880701, 'keep' => ['Bo']]);
$dryJson = (string) json_encode($dry, JSON_UNESCAPED_UNICODE);
so_assert((int) ($dry['dry'] ?? 0) === 1 && (int) ($dry['written'] ?? 1) === 0 && (int) ($dry['owner_count'] ?? 0) === 1, 'torr körning skriver inte');
so_assert((string) ($dry['owners'][0]['name'] ?? '') === 'Bo Två' && (string) ($dry['owners'][0]['andel'] ?? '') === '1/2', 'torr körning visar namn och andel');
so_assert(!array_key_exists('personnummer', (array) ($dry['owners'][0] ?? [])), 'svaret har inget personnummerfält');
so_no_pnr($dryJson, 'torr körning saknar personnummer');
so_assert((string) (jeab_purchase_get(880701)['huvudsokande'] ?? '') === 'Ada En', 'torr körning lämnar ägarna');
so_assert((int) ($GLOBALS['_JEAB_LM_SEARCH_CALLS'] ?? 0) === 0, 'torr körning anropar inte Lantmäteriet');

$unconfirmed = jeab_ordrar_set_owners_run($store, [
    'order_id' => 880701,
    'keep' => [1],
    'dry' => 0,
    'confirm' => 'nej',
]);
so_assert(($unconfirmed['ok'] ?? true) === false && (int) ($unconfirmed['written'] ?? 1) === 0, 'dry=0 utan confirm skriver inte');

$kept = jeab_ordrar_set_owners_run($store, [
    'order_id' => 880701,
    'keep' => [0, 'Bo'],
    'dry' => 0,
    'confirm' => 'SAM',
]);
$keptJson = (string) json_encode($kept, JSON_UNESCAPED_UNICODE);
so_assert((int) ($kept['written'] ?? 0) === 1 && (int) ($kept['owner_count'] ?? 0) === 2, 'index och förnamn behålls');
so_assert((string) ($kept['owners'][0]['name'] ?? '') === 'Ada En' && (string) ($kept['owners'][1]['name'] ?? '') === 'Bo Två', 'svaret visar de kvarvarande namnen');
so_assert((string) ($kept['owners'][0]['andel'] ?? '') === '1/2' && (string) ($kept['owners'][1]['andel'] ?? '') === '1/2', 'svaret visar andelarna');
so_no_pnr($keptJson, 'skrivsvaret saknar personnummer');
$now = (array) jeab_purchase_get(880701);
$nowNames = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), is_array($now['owners'] ?? null) ? $now['owners'] : []);
so_assert($nowNames === ['Ada En', 'Bo Två'], 'Cia är borttagen');
so_assert((string) ($now['fastighetsbeteckning'] ?? '') === 'SOLLENTUNA LERTAVLAN 4', 'beteckningen ligger kvar');
so_assert((int) ($GLOBALS['_JEAB_LM_SEARCH_CALLS'] ?? 0) === 0, 'skrivningen anropar inte Lantmäteriet');
so_no_pnr(jeab_fastighet_log_text(), 'loggen saknar personnummer');
$undo = jeab_fastighet_undo_get((string) ($kept['batch_id'] ?? ''));
so_assert(is_array($undo) && (string) ($undo['order']['huvudsokande'] ?? '') === 'Ada En', 'ångringen har den gamla huvudägaren');
$undoNames = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), is_array($undo['order']['owners'] ?? null) ? $undo['order']['owners'] : []);
so_assert($undoNames === ['Ada En', 'Bo Två', 'Cia Tre'], 'ångringen har alla tre ägare');

$back = jeab_order_fastighet_undo($store, ['batch_id' => (string) ($kept['batch_id'] ?? '')]);
so_assert(($back['ok'] ?? false) === true, 'ägarurvalet kan ångras');
$restored = (array) jeab_purchase_get(880701);
$restoredNames = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), is_array($restored['owners'] ?? null) ? $restored['owners'] : []);
so_assert($restoredNames === ['Ada En', 'Bo Två', 'Cia Tre'], 'ångring lägger tillbaka Cia');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
so_assert(str_contains($hook, "=== 'ordrar.set_owners'") && str_contains($hook, 'jeab_ordrar_set_owners_dispatch'), 'hook.php tar ordrar.set_owners');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$start = strpos($src, 'function jeab_ordrar_set_owners_run(');
$end = strpos($src, 'function jeab_ordrar_set_owners_dispatch(');
so_assert($start !== false && $end !== false && $end > $start, 'funktionen finns');
$body = substr($src, (int) $start, (int) $end - (int) $start);
foreach (['/create_booking\s*\(/', '/\w*fortnox\w*\s*\(/i', '/sms_send\s*\(/', '/system_mail\w*\s*\(/', '/notify\w*\s*\(/', '/beteckning_lookup\s*\(/', '/live_address_search\s*\(/', '/LantmaterietAPI/'] as $bad) {
    so_assert(preg_match($bad, $body) !== 1, 'set_owners anropar inte ' . $bad);
}
so_assert(str_contains($body, 'jeab_fastighet_undo_add'), 'gamla ägare sparas som ångring före skrivning');
so_assert(str_contains($body, 'jeab_rot_lookup_address('), 'adress kör jeab_rot_lookup_address med objekt-id');
so_assert(str_contains($body, 'jeab_ordrar_lm_candidate_list('), 'adressen går via autocomplete');

$rotDir = $tmp . '/rot';
@mkdir($rotDir, 0775, true);
file_put_contents($rotDir . '/data_scraping.php', <<<'PHP'
<?php
class LantmaterietAPI {
    public $data = [];
    public function search($addressText, $uuid = '') {
        $GLOBALS['SO_LM_CALLS'][] = (string) $addressText . "\t" . (string) $uuid;
        if (stripos((string) $addressText, 'Tvillingstigen') !== false) {
            return [
                'addressSuggestions' => [
                    ['adress' => 'Tvillingstigen 2, 192 51 Sollentuna', 'objektidentitet' => 'obj-tv-a'],
                    ['adress' => 'Tvillingstigen 2 19251 Sollentuna', 'objektidentitet' => 'obj-tv-b'],
                ],
            ];
        }
        if (stripos((string) $addressText, 'Flottarestigen') !== false) {
            if ((string) $uuid === 'obj-flot-6') {
                return [[
                    'objektidentitet' => 'obj-flot-6',
                    'fastighetsbeteckning' => 'Sollentuna Lertavlan 4',
                    'matchedAddress' => 'Flottarestigen 6, 192 51 Sollentuna',
                    'postnummer' => '19251',
                    'postort' => 'Sollentuna',
                    'owners' => [
                        [
                            'name' => 'Wouter van der Wijngaart',
                            'personnummer' => '197001011111',
                            'address' => 'Flottarestigen 6, Sollentuna',
                            'andel' => '1/2',
                            'lagfart' => '2006-06-19',
                        ],
                        [
                            'name' => 'Saija Marja Metsola',
                            'personnummer' => '197001012222',
                            'address' => 'Flottarestigen 6, Sollentuna',
                            'andel' => '1/2',
                            'lagfart' => '2006-06-19',
                        ],
                    ],
                ]];
            }
            return [
                'addressSuggestions' => [
                    ['adress' => 'Flottarestigen 6, 192 51 Sollentuna', 'objektidentitet' => 'obj-flot-6'],
                    ['adress' => 'Flottarestigen 66, 192 51 Sollentuna', 'objektidentitet' => 'obj-flot-66'],
                    ['adress' => 'Flottarestigen 6, 191 41 Sollentuna', 'objektidentitet' => 'obj-flot-191'],
                ],
            ];
        }
        return [
            [
                'objektidentitet' => 'obj-annan',
                'fastighetsbeteckning' => 'Sollentuna Annan 9',
                'matchedAddress' => 'Ägargatan 4',
                'postnummer' => '19141',
                'postort' => 'Sollentuna',
                'owners' => [[
                    'name' => 'Noa Fel',
                    'personnummer' => '196303036666',
                    'address' => 'Ägargatan 4',
                    'andel' => '1/1',
                    'lagfart' => '2011-01-01',
                ]],
            ],
            [
                'objektidentitet' => 'obj-lerta',
                'fastighetsbeteckning' => 'Sollentuna Lertavlan 4',
                'matchedAddress' => 'Ägargatan 4',
                'postnummer' => '19141',
                'postort' => 'Sollentuna',
                'owners' => [
                    [
                        'name' => 'Maj Ny',
                        'personnummer' => '197001014444',
                        'address' => 'Ägargatan 4',
                        'andel' => '1/2',
                        'lagfart' => '2024-03-01',
                    ],
                    [
                        'name' => 'Ove Ny',
                        'personnummer' => '197202025555',
                        'address' => 'Ägargatan 4',
                        'andel' => '1/2',
                        'inskrivningsdag' => '2019-11-02',
                    ],
                ],
            ],
        ];
    }
}
PHP);
$GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES'] = [$rotDir . '/data_scraping.php'];
$GLOBALS['SO_LM_CALLS'] = [];

$maj = jeab_ordrar_format_pnr('197001014444');
$ove = jeab_ordrar_format_pnr('197202025555');
jeab_purchase_put([
    'reminder_id' => 880702,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Ada En',
    'huvudsokande' => 'Ada En',
    'personnummer' => $ada,
    'fastighetsbeteckning' => 'SOLLENTUNA LERTAVLAN 4',
    'address' => 'Ägargatan 4, Sollentuna',
    'owners' => [
        ['name' => 'Ada En', 'personnummer' => $ada, 'address' => 'Ägargatan 4', 'andel' => '1/1', 'lagfart' => ''],
    ],
    'medsokande' => [],
    'pris' => 15000,
    'steps' => [],
]);

$lookDry = jeab_ordrar_set_owners_run($store, [
    'order_id' => 880702,
    'address' => 'Ägargatan 4, 191 41 Sollentuna',
    'objektidentitet' => 'obj-bada',
]);
$lookDryJson = (string) json_encode($lookDry, JSON_UNESCAPED_UNICODE);
$lookNames = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), is_array($lookDry['owners'] ?? null) ? $lookDry['owners'] : []);
so_assert((int) ($lookDry['dry'] ?? 0) === 1 && (int) ($lookDry['written'] ?? 1) === 0, 'adressuppslag är torrt som standard');
so_assert($lookNames === ['Maj Ny', 'Ove Ny'], 'flera fastigheter: bara Lertavlan 4, fick ' . implode(', ', $lookNames));
so_assert((string) ($lookDry['owners'][0]['andel'] ?? '') === '1/2' && (string) ($lookDry['owners'][1]['andel'] ?? '') === '1/2', 'svaret visar andelarna');
so_assert(!array_key_exists('personnummer', (array) ($lookDry['owners'][0] ?? [])), 'adressvaret har inget personnummerfält');
so_no_pnr($lookDryJson, 'adressvaret saknar personnummer');
so_assert((string) (jeab_purchase_get(880702)['huvudsokande'] ?? '') === 'Ada En', 'torrt adressuppslag skriver inte');
$lmCalls = implode("\n", is_array($GLOBALS['SO_LM_CALLS'] ?? null) ? $GLOBALS['SO_LM_CALLS'] : []);
so_assert(str_contains($lmCalls, 'obj-bada'), 'objektidentitet följer med till uppslaget');

$lookDenied = jeab_ordrar_set_owners_run($store, [
    'order_id' => 880702,
    'address' => 'Ägargatan 4, 191 41 Sollentuna',
    'dry' => 0,
    'confirm' => 'nej',
]);
so_assert(($lookDenied['ok'] ?? true) === false && (int) ($lookDenied['written'] ?? 1) === 0, 'adresskrivning kräver confirm=SAM');
so_assert((string) (jeab_purchase_get(880702)['huvudsokande'] ?? '') === 'Ada En', 'nekad adresskrivning lämnar ägaren');

$lookWrote = jeab_ordrar_set_owners_run($store, [
    'order_id' => 880702,
    'address' => 'Ägargatan 4, 191 41 Sollentuna',
    'objektidentitet' => 'obj-bada',
    'dry' => 0,
    'confirm' => 'SAM',
]);
$lookWroteJson = (string) json_encode($lookWrote, JSON_UNESCAPED_UNICODE);
so_assert((int) ($lookWrote['written'] ?? 0) === 1, 'bekräftad adresskrivning sparar');
so_no_pnr($lookWroteJson, 'adresskrivningens svar saknar personnummer');
$replaced = (array) jeab_purchase_get(880702);
$replacedOwners = is_array($replaced['owners'] ?? null) ? $replaced['owners'] : [];
$replacedNames = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), $replacedOwners);
so_assert($replacedNames === ['Maj Ny', 'Ove Ny'], 'orderns ägare är bytta, fick ' . implode(', ', $replacedNames));
so_assert((string) ($replacedOwners[0]['personnummer'] ?? '') === $maj && (string) ($replacedOwners[1]['personnummer'] ?? '') === $ove, 'personnummer lagras som vid vanligt uppslag');
so_assert((string) ($replacedOwners[0]['andel'] ?? '') === '1/2' && (string) ($replacedOwners[1]['andel'] ?? '') === '1/2', 'andelarna lagras');
so_assert((string) ($replacedOwners[0]['lagfart'] ?? '') === '2024-03-01' && (string) ($replacedOwners[1]['lagfart'] ?? '') === '2019-11-02', 'lagfart lagras');
so_assert((string) ($replaced['huvudsokande'] ?? '') === 'Maj Ny' && (string) ($replaced['personnummer'] ?? '') === $maj, 'huvudägare och personnummer följer uppslaget');
so_no_pnr(jeab_fastighet_log_text(), 'loggen saknar personnummer efter adresskrivning');
$lookUndo = jeab_fastighet_undo_get((string) ($lookWrote['batch_id'] ?? ''));
so_assert(is_array($lookUndo) && (string) ($lookUndo['order']['huvudsokande'] ?? '') === 'Ada En', 'ångringen har den gamla ägaren');

jeab_purchase_put([
    'reminder_id' => 880703,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Ada En',
    'huvudsokande' => 'Ada En',
    'personnummer' => $ada,
    'fastighetsbeteckning' => 'SOLLENTUNA LERTAVLAN 4',
    'address' => 'Flottarestigen 6, Sollentuna',
    'owners' => [
        ['name' => 'Ada En', 'personnummer' => $ada, 'address' => 'Flottarestigen 6', 'andel' => '1/1', 'lagfart' => ''],
    ],
    'medsokande' => [],
    'pris' => 15000,
    'steps' => [],
]);
$GLOBALS['SO_LM_CALLS'] = [];
$flot = jeab_ordrar_set_owners_run($store, [
    'order_id' => 880703,
    'query' => 'Flottarestigen 6 19251 Sollentuna',
]);
$flotJson = (string) json_encode($flot, JSON_UNESCAPED_UNICODE);
$flotNames = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), is_array($flot['owners'] ?? null) ? $flot['owners'] : []);
$flotCalls = is_array($GLOBALS['SO_LM_CALLS'] ?? null) ? $GLOBALS['SO_LM_CALLS'] : [];
so_assert((string) ($flot['error'] ?? '') !== 'ingen ägare från adressen', 'Flottarestigen 6 19251 ger ägare');
so_assert($flotNames === ['Wouter van der Wijngaart', 'Saija Marja Metsola'], 'Flottarestigen ger Wouter och Saija, fick ' . implode(', ', $flotNames));
so_assert((string) ($flot['owners'][0]['andel'] ?? '') === '1/2' && (string) ($flot['owners'][1]['andel'] ?? '') === '1/2', 'Flottarestigen visar andel 1/2');
so_assert(!str_contains($flotJson, 'Paulina') && !str_contains($flotJson, 'Rikard'), 'den andra fastigheten följer inte med');
so_no_pnr($flotJson, 'Flottarestigen-svaret saknar personnummer');
so_assert(in_array("Flottarestigen 6 19251 Sollentuna\t", $flotCalls, true), 'autocomplete söker frågan, fick ' . implode(' | ', $flotCalls));
so_assert(in_array("Flottarestigen 6 Sollentuna\tobj-flot-6", $flotCalls, true), 'ägaruppslaget skickar radens objekt-id, fick ' . implode(' | ', $flotCalls));
$flotWrote = jeab_ordrar_set_owners_run($store, [
    'order_id' => 880703,
    'query' => 'Flottarestigen 6 19251 Sollentuna',
    'dry' => 0,
    'confirm' => 'SAM',
]);
so_assert((int) ($flotWrote['written'] ?? 0) === 1, 'bekräftad exakt adress sparar');
$flotStored = (array) jeab_purchase_get(880703);
$flotStoredOwners = is_array($flotStored['owners'] ?? null) ? $flotStored['owners'] : [];
$flotStoredNames = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), $flotStoredOwners);
so_assert($flotStoredNames === ['Wouter van der Wijngaart', 'Saija Marja Metsola'], 'sparade ägare är Wouter och Saija');
so_assert((string) ($flotStoredOwners[0]['lagfart'] ?? '') === '2006-06-19' && (string) ($flotStoredOwners[1]['lagfart'] ?? '') === '2006-06-19', 'lagfarten är 2006-06-19');
so_no_pnr((string) json_encode($flotWrote, JSON_UNESCAPED_UNICODE), 'sparsvaret saknar personnummer');

$GLOBALS['SO_LM_CALLS'] = [];
$twin = jeab_ordrar_set_owners_run($store, [
    'order_id' => 880703,
    'address' => 'Tvillingstigen 2 19251 Sollentuna',
]);
$twinIds = array_map(static fn(array $row): string => (string) ($row['objektId'] ?? ''), is_array($twin['candidates'] ?? null) ? $twin['candidates'] : []);
so_assert(($twin['ok'] ?? true) === false && (int) ($twin['written'] ?? 1) === 0, 'två exakta rader skriver inget');
so_assert((string) ($twin['error'] ?? '') === 'flera adresser', 'två exakta rader ber om val');
so_assert($twinIds === ['obj-tv-a', 'obj-tv-b'], 'kandidaterna har objekt-id, fick ' . implode(', ', $twinIds));
so_assert((string) (jeab_purchase_get(880703)['huvudsokande'] ?? '') === 'Wouter van der Wijngaart', 'tvetydig adress lämnar ägarna');
$twinCalls = implode("\n", is_array($GLOBALS['SO_LM_CALLS'] ?? null) ? $GLOBALS['SO_LM_CALLS'] : []);
so_assert(!str_contains($twinCalls, 'obj-tv-a') && !str_contains($twinCalls, 'obj-tv-b'), 'tvetydig adress gör inget ägaruppslag');

$GLOBALS['SO_LM_CALLS'] = [];
$byId = jeab_ordrar_set_owners_run($store, [
    'order_id' => 880703,
    'address' => 'Flottarestigen 6 19251 Sollentuna',
    'objektId' => 'obj-flot-6',
]);
$byIdNames = array_map(static fn(array $owner): string => (string) ($owner['name'] ?? ''), is_array($byId['owners'] ?? null) ? $byId['owners'] : []);
$byIdCalls = is_array($GLOBALS['SO_LM_CALLS'] ?? null) ? $GLOBALS['SO_LM_CALLS'] : [];
so_assert($byIdNames === ['Wouter van der Wijngaart', 'Saija Marja Metsola'], 'objektId hoppar över autocomplete');
so_assert($byIdCalls !== [] && !str_contains(implode("\n", $byIdCalls), 'addressSuggestions') && str_contains(implode("\n", $byIdCalls), 'obj-flot-6'), 'direkt objektId söker fastigheten');
so_assert(!in_array("Flottarestigen 6 19251 Sollentuna\t", $byIdCalls, true), 'direkt objektId gör ingen autocomplete-sökning');

$candDenied = jeab_ordrar_lm_candidates_dispatch($store, ['query' => 'Flottarestigen 6 19251 Sollentuna']);
so_assert(($candDenied['ok'] ?? true) === false && (int) ($candDenied['status'] ?? 0) === 401, 'lm_candidates kräver token');
$cand = jeab_ordrar_lm_candidates_run($store, ['query' => 'Flottarestigen 6 19251 Sollentuna', 'dry' => 0, 'confirm' => 'SAM']);
$candJson = (string) json_encode($cand, JSON_UNESCAPED_UNICODE);
$candRows = is_array($cand['candidates'] ?? null) ? $cand['candidates'] : [];
$candIds = array_map(static fn(array $row): string => (string) ($row['objektId'] ?? ''), $candRows);
so_assert((int) ($cand['dry'] ?? 0) === 1 && (int) ($cand['written'] ?? 1) === 0, 'lm_candidates är alltid torr');
so_assert($candIds === ['obj-flot-6', 'obj-flot-66', 'obj-flot-191'], 'lm_candidates listar objekt-id, fick ' . implode(', ', $candIds));
so_assert((string) ($candRows[0]['address'] ?? '') === 'Flottarestigen 6, 192 51 Sollentuna', 'lm_candidates har adressen');
so_assert(!str_contains($candJson, 'Wouter') && !str_contains($candJson, 'Paulina'), 'lm_candidates har inga ägarnamn');
so_no_pnr($candJson, 'lm_candidates saknar personnummer');
$hookLm = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
so_assert(str_contains($hookLm, "=== 'ordrar.lm_candidates'") && str_contains($hookLm, 'jeab_ordrar_lm_candidates_dispatch'), 'hook.php tar ordrar.lm_candidates');

echo "ordrar set_owners: ok\n";
