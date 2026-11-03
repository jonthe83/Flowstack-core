<?php

declare(strict_types=1);

/**
 * Hämta fastighetsinfo igen låter Lantmäteriets nya träff vinna.
 * ordrar.set_fastighet är torr som standard och kräver confirm=SAM för att skriva.
 * Inget personnummer i svar eller logg. Inget mejl, sms eller Fortnox.
 */

$tmp = sys_get_temp_dir() . '/jeab-set-fastighet-' . getmypid();
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

function sf_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function sf_old_pnr(): string
{
    return implode('', ['19', '55', '03', '03', '11', '11']);
}

function sf_new_pnr(): string
{
    return implode('', ['19', '66', '02', '02', '22', '22']);
}

function sf_bet_pnr(): string
{
    return implode('', ['19', '70', '01', '01', '33', '33']);
}

function sf_no_pnr(string $hay, string $label): void
{
    $bad = false;
    foreach ([sf_old_pnr(), sf_new_pnr(), sf_bet_pnr()] as $digits) {
        $norm = function_exists('jeab_pnr_normalize') ? jeab_pnr_normalize($digits) : $digits;
        $bad = $bad || ($digits !== '' && str_contains($hay, $digits)) || ($norm !== '' && str_contains($hay, $norm));
    }
    sf_assert(!$bad, $label);
}

$mails = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$mails): array {
    $mails[] = $opts;
    return ['ok' => true, 'path' => 'stub', 'error' => null, 'id' => 'stub'];
};

$rotDir = $tmp . '/rot';
@mkdir($rotDir, 0775, true);
file_put_contents($rotDir . '/data_scraping.php', <<<'PHP'
<?php
class LantmaterietAPI {
    public $data = [];
    public function search($addressText, $uuid = '') {
        $GLOBALS['SF_LM_CALLS'][] = (string) $addressText . "\t" . (string) $uuid;
        $t = (string) $addressText;
        if (stripos($t, 'Rättgatan 9') !== false) {
            $this->data = [
                'fastighetsbeteckning' => 'Rättby 2:2',
                'matchedAddress' => 'Rättgatan 9',
                'postnummer' => '11133',
                'postort' => 'Teststad',
                'owners' => [
                    ['name' => 'Ny Person', 'personnummer' => '196602022222', 'address' => 'Rättgatan 9', 'andel' => '1/1'],
                ],
            ];
            return true;
        }
        if (stripos($t, 'Titelvägen 3') !== false) {
            $this->data = [
                [
                    'fastighetsbeteckning' => 'Feltitel 1:1',
                    'matchedAddress' => 'Titelvägen 3',
                    'postnummer' => '11144',
                    'postort' => 'Teststad',
                    'owners' => [
                        ['name' => 'Titel Ägare', 'personnummer' => '195503031111', 'address' => 'Titelvägen 3', 'andel' => '1/1'],
                    ],
                ],
                [
                    'fastighetsbeteckning' => 'Testby 4:2',
                    'matchedAddress' => 'Titelvägen 3',
                    'postnummer' => '11122',
                    'postort' => 'Teststad',
                    'owners' => [
                        ['name' => 'Ny Ägare', 'personnummer' => '197001013333', 'address' => 'Titelvägen 3', 'andel' => '1/1'],
                    ],
                ],
            ];
            return true;
        }
        return false;
    }
}
PHP);
$GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES'] = [$rotDir . '/data_scraping.php'];
$GLOBALS['SF_LM_CALLS'] = [];

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
jeab_ordrar_external_live(true);

$qOrder = jeab_ordrar_lookup_query(
    ['address' => 'Felgatan 1, Teststad', 'title' => 'Titelvägen 3 Teststad', 'ort' => 'Teststad'],
    ['address' => 'Rättgatan 9, Teststad', 'ort' => 'Teststad']
);
sf_assert(str_starts_with($qOrder, 'Rättgatan 9'), 'orderns gata går före leadens adress och titel');
$qLead = jeab_ordrar_lookup_query(
    ['address' => 'Felgatan 1, Teststad', 'title' => 'Titelvägen 3', 'ort' => 'Teststad'],
    ['ort' => 'Teststad']
);
sf_assert(str_starts_with($qLead, 'Felgatan 1'), 'utan egen gata används leadens adress');
$qTown = jeab_ordrar_lookup_query(null, [
    'address' => 'Flottarestigen 6 Sollentuna Sollentuna',
    'ort' => 'Sollentuna',
]);
sf_assert(str_starts_with($qTown, 'Flottarestigen 6') && !str_contains($qTown, 'Sollentuna Sollentuna'), 'orten Sollentuna dubbleras inte, fick ' . $qTown);

$oldPnr = jeab_ordrar_format_pnr(sf_old_pnr());
$mark = jeab_ordrar_identity_value(['fastighetsbeteckning' => 'Testby 4:2'], 'fastighetsbeteckning');
jeab_purchase_put([
    'reminder_id' => 880610,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Gammal Ägare',
    'huvudsokande' => 'Gammal Ägare',
    'personnummer' => $oldPnr,
    'fastighetsbeteckning' => 'Testby 4:2',
    'identity_source' => ['fastighetsbeteckning' => ['src' => 'manual', 'value' => $mark, 'address' => '', 'at' => '2026-10-01']],
    'address' => 'Titelvägen 3, Teststad',
    'huvudsokande_address' => 'Titelvägen 3, Teststad',
    'ort' => 'Teststad',
    'owners' => [[
        'name' => 'Gammal Ägare',
        'personnummer' => $oldPnr,
        'address' => 'Titelvägen 3',
        'andel' => '1/1',
        'lagfart' => '',
    ]],
    'medsokande' => [],
    'pris' => 15000,
    'steps' => [],
]);
$GLOBALS['SF_LM_CALLS'] = [];
$suggest = jeab_ordrar_confirm_suggest((array) jeab_purchase_get(880610), [
    'address' => 'Felgatan 1, Teststad',
    'title' => 'Titelvägen 3',
]);
$calls = implode('|', $GLOBALS['SF_LM_CALLS']);
sf_assert(str_contains($calls, 'Titelvägen') && !str_contains($calls, '4:2') && !str_contains($calls, 'Testby'), 'sparad beteckning väljs ur adressökningen, inte som söktext');
sf_assert(($suggest['fastighetsbeteckning'] ?? '') === 'TESTBY 4:2', 'uppslaget tar beteckningens träff');
sf_assert(($suggest['huvudsokande'] ?? '') !== 'Titel Ägare', 'titelns ägare används inte');

jeab_purchase_put([
    'reminder_id' => 880611,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Gammal Ägare',
    'huvudsokande' => 'Gammal Ägare',
    'personnummer' => $oldPnr,
    'fastighetsbeteckning' => 'FEL 1:1',
    'address' => 'Rättgatan 9, Teststad',
    'ort' => 'Teststad',
    'owners' => [[
        'name' => 'Gammal Ägare',
        'personnummer' => $oldPnr,
        'address' => 'Rättgatan 9',
        'andel' => '1/1',
        'lagfart' => '',
    ]],
    'medsokande' => [],
    'pris' => 15000,
    'steps' => [],
]);
$kept = jeab_ordrar_bekrafta_fill($store, ['order_id' => 880611, 'dry' => 0]);
sf_assert(($kept['ok'] ?? false) === true && (int) ($kept['refetch'] ?? 1) === 0, 'vanlig fyllning är inte en omhämtning');
$keptRow = (array) jeab_purchase_get(880611);
sf_assert((string) ($keptRow['fastighetsbeteckning'] ?? '') === 'FEL 1:1', 'vanlig fyllning lämnar ifylld beteckning');
sf_assert((string) ($keptRow['huvudsokande'] ?? '') === 'Gammal Ägare', 'vanlig fyllning lämnar ägarnamnet');
sf_assert((string) ($keptRow['personnummer'] ?? '') === $oldPnr, 'vanlig fyllning lämnar personnumret');

$fresh = jeab_ordrar_bekrafta_fill($store, ['order_id' => 880611, 'dry' => 0, 'refetch' => 1]);
$freshJson = (string) json_encode($fresh, JSON_UNESCAPED_UNICODE);
sf_no_pnr($freshJson, 'omhämtningens svar saknar personnummer');
sf_assert(($fresh['ok'] ?? false) === true && (int) ($fresh['written'] ?? 0) === 1 && (int) ($fresh['refetch'] ?? 0) === 1, 'omhämtning skriver');
$freshRow = (array) jeab_purchase_get(880611);
sf_assert((string) ($freshRow['fastighetsbeteckning'] ?? '') === 'RÄTTBY 2:2', 'ny beteckning från Lantmäteriet ligger kvar');
sf_assert((string) ($freshRow['huvudsokande'] ?? '') === 'Ny Person', 'nytt ägarnamn ligger kvar');
sf_assert((string) ($freshRow['personnummer'] ?? '') === jeab_ordrar_format_pnr(sf_new_pnr()), 'nytt personnummer ligger kvar');
sf_assert((string) ($freshRow['owners'][0]['name'] ?? '') === 'Ny Person', 'ägarlistan ersätts');
$undo = jeab_fastighet_undo_get((string) ($fresh['batch_id'] ?? ''));
sf_assert(is_array($undo) && (string) ($undo['order']['fastighetsbeteckning'] ?? '') === 'FEL 1:1', 'ångringen har den gamla beteckningen');
sf_assert((string) ($undo['order']['huvudsokande'] ?? '') === 'Gammal Ägare', 'ångringen har det gamla namnet');
sf_assert((string) ($undo['order']['personnummer'] ?? '') === $oldPnr, 'ångringen har det gamla personnumret');
sf_no_pnr(jeab_fastighet_log_text(), 'loggen efter omhämtning saknar personnummer');

$denied = jeab_ordrar_set_fastighet_dispatch($store, [
    'order_id' => 880610,
    'fastighetsbeteckning' => 'ANNAN 8:8',
    'dry' => 0,
    'confirm' => 'SAM',
]);
sf_assert(($denied['ok'] ?? true) === false && (int) ($denied['status'] ?? 0) === 401, 'hooken kräver token');

$dry = jeab_ordrar_set_fastighet_run($store, [
    'order_id' => 880610,
    'fastighetsbeteckning' => 'ANNAN 8:8',
]);
sf_assert(($dry['ok'] ?? false) === true && (int) ($dry['dry'] ?? 0) === 1 && (int) ($dry['written'] ?? 1) === 0, 'dry saknas skriver inget');
sf_assert((string) ($dry['before'] ?? '') === 'Testby 4:2' && (string) ($dry['after'] ?? '') === 'ANNAN 8:8', 'dry visar före och efter');
sf_assert((string) (jeab_purchase_get(880610)['fastighetsbeteckning'] ?? '') === 'Testby 4:2', 'dry lämnar ordern');

$unconfirmed = jeab_ordrar_set_fastighet_run($store, [
    'order_id' => 880610,
    'fastighetsbeteckning' => 'ANNAN 8:8',
    'dry' => 0,
    'confirm' => 'nej',
]);
sf_assert(($unconfirmed['ok'] ?? true) === false && (int) ($unconfirmed['written'] ?? 1) === 0, 'dry=0 utan confirm skriver inte');
sf_assert((string) (jeab_purchase_get(880610)['fastighetsbeteckning'] ?? '') === 'Testby 4:2', 'nekad bekräftelse lämnar beteckningen');

$set = jeab_ordrar_set_fastighet_run($store, [
    'order_id' => 880610,
    'fastighetsbeteckning' => 'ANNAN 8:8',
    'dry' => 0,
    'confirm' => 'SAM',
]);
$setJson = (string) json_encode($set, JSON_UNESCAPED_UNICODE);
sf_no_pnr($setJson, 'set-svaret saknar personnummer');
sf_assert(($set['ok'] ?? false) === true && (int) ($set['written'] ?? 0) === 1, 'confirm skriver beteckningen');
$setRow = (array) jeab_purchase_get(880610);
sf_assert((string) ($setRow['fastighetsbeteckning'] ?? '') === 'ANNAN 8:8', 'beteckningen sparas som i Objekt');
sf_assert(jeab_ordrar_identity_source($setRow, 'fastighetsbeteckning') === 'manual', 'sparad beteckning räknas som manuell');
sf_assert((string) ($setRow['owners'][0]['name'] ?? '') === 'Gammal Ägare', 'utan refetch_owners ligger ägarna kvar');
sf_assert((string) ($setRow['fortnox_order'] ?? '') === '', 'ingen Fortnox-order skapas');

$preview = jeab_ordrar_set_fastighet_run($store, [
    'order_id' => 880610,
    'fastighetsbeteckning' => 'Testby 4:2',
    'refetch_owners' => 1,
]);
sf_assert((int) ($preview['dry'] ?? 0) === 1 && (int) ($preview['written'] ?? 1) === 0 && (int) ($preview['owner_count'] ?? 0) === 1, 'torr ägarhämtning skriver inte');
sf_assert((int) ($preview['lookup_empty'] ?? 1) === 0, 'torr ägarhämtning ser träffen');
sf_assert((string) ($preview['owners'][0]['name'] ?? '') === 'Ny Ägare', 'torr ägarhämtning visar ägarnamnet');
sf_assert(!array_key_exists('personnummer', (array) ($preview['owners'][0] ?? [])), 'torr ägarhämtning har inget personnummerfält');
sf_no_pnr((string) json_encode($preview, JSON_UNESCAPED_UNICODE), 'torr ägarhämtning saknar personnummer');
$miss = jeab_ordrar_set_fastighet_run($store, [
    'order_id' => 880610,
    'fastighetsbeteckning' => 'SAKNAS 9:9',
    'refetch_owners' => 1,
]);
sf_assert((int) ($miss['lookup_empty'] ?? 0) === 1 && (int) ($miss['owner_count'] ?? 1) === 0, 'utan matchande beteckning är uppslaget tomt');
sf_assert(($miss['owners'] ?? null) === [], 'tom träff lämnar inga ägarnamn');
sf_assert((string) (jeab_purchase_get(880610)['owners'][0]['name'] ?? '') === 'Gammal Ägare', 'tom träff byter inte ägare');
sf_assert((string) (jeab_purchase_get(880610)['owners'][0]['name'] ?? '') === 'Gammal Ägare', 'torr ägarhämtning lämnar ägarna');

$replaced = jeab_ordrar_set_fastighet_run($store, [
    'order_id' => 880610,
    'fastighetsbeteckning' => 'Testby 4:2',
    'refetch_owners' => true,
    'dry' => 0,
    'confirm' => 'SAM',
]);
sf_assert((int) ($replaced['written'] ?? 0) === 1, 'refetch_owners skriver');
sf_assert((string) ($replaced['owners'][0]['name'] ?? '') === 'Ny Ägare', 'ägarbytet visar det nya namnet');
sf_no_pnr((string) json_encode($replaced, JSON_UNESCAPED_UNICODE), 'ägarbytet saknar personnummer i svaret');
$now = (array) jeab_purchase_get(880610);
sf_assert((string) ($now['fastighetsbeteckning'] ?? '') === 'Testby 4:2', 'beteckningen från anropet ligger kvar');
sf_assert((string) ($now['huvudsokande'] ?? '') === 'Ny Ägare', 'ägarna ersätts från beteckningens uppslag');
sf_assert((string) ($now['owners'][0]['name'] ?? '') === 'Ny Ägare', 'gamla ägarnamnet är borta');
sf_assert((string) ($now['personnummer'] ?? '') === jeab_ordrar_format_pnr(sf_bet_pnr()), 'personnumret kommer från uppslaget');
$backup = jeab_fastighet_undo_get((string) ($replaced['batch_id'] ?? ''));
sf_assert(is_array($backup) && (string) ($backup['order']['huvudsokande'] ?? '') === 'Gammal Ägare', 'ångringen togs innan ägarbytet');
sf_assert((string) ($backup['order']['fastighetsbeteckning'] ?? '') === 'ANNAN 8:8', 'ångringen har beteckningen före bytet');
sf_no_pnr(jeab_fastighet_log_text(), 'loggen efter set_fastighet saknar personnummer');

$back = jeab_order_fastighet_undo($store, ['batch_id' => (string) ($replaced['batch_id'] ?? '')]);
sf_assert(($back['ok'] ?? false) === true, 'ägarbytet kan ångras');
$restored = (array) jeab_purchase_get(880610);
sf_assert((string) ($restored['huvudsokande'] ?? '') === 'Gammal Ägare', 'ångring lägger tillbaka namnet');
sf_assert((string) ($restored['fastighetsbeteckning'] ?? '') === 'ANNAN 8:8', 'ångring lägger tillbaka beteckningen');

jeab_purchase_put([
    'reminder_id' => 880612,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Gammal Ägare',
    'huvudsokande' => 'Gammal Ägare',
    'personnummer' => $oldPnr,
    'fastighetsbeteckning' => 'Testby 4:2',
    'identity_source' => ['fastighetsbeteckning' => ['src' => 'manual', 'value' => $mark, 'address' => '', 'at' => '2026-10-01']],
    'address' => 'Titelvägen 3, Teststad',
    'ort' => 'Teststad',
    'owners' => [[
        'name' => 'Gammal Ägare',
        'personnummer' => $oldPnr,
        'address' => 'Titelvägen 3',
        'andel' => '1/1',
        'lagfart' => '',
    ]],
    'medsokande' => [],
    'pris' => 15000,
    'steps' => [],
]);
$GLOBALS['SF_LM_CALLS'] = [];
$button = jeab_order_fastighet_refresh($store, ['order_id' => 880612, 'dry' => 0]);
sf_assert(($button['ok'] ?? false) === true && (int) ($button['written'] ?? 0) === 1, 'Hämta fastighetsinfo igen skriver den nya träffen');
$buttonCalls = implode('|', $GLOBALS['SF_LM_CALLS']);
sf_assert(str_contains($buttonCalls, 'Titelvägen') && !str_contains($buttonCalls, '4:2'), 'knappen söker adressen och väljer den sparade beteckningen');
$buttonRow = (array) jeab_purchase_get(880612);
sf_assert((string) ($buttonRow['owners'][0]['name'] ?? '') === 'Ny Ägare', 'knappen ersätter ägarlistan');
sf_assert((string) ($buttonRow['huvudsokande'] ?? '') !== 'Gammal Ägare', 'knappen lämnar inte det gamla ägarnamnet');
sf_no_pnr((string) json_encode($button, JSON_UNESCAPED_UNICODE), 'knappens svar saknar personnummer');

$missMark = jeab_ordrar_identity_value(['fastighetsbeteckning' => 'SAKNAS 9:9'], 'fastighetsbeteckning');
jeab_purchase_put([
    'reminder_id' => 880614,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Gammal Ägare',
    'huvudsokande' => 'Gammal Ägare',
    'personnummer' => $oldPnr,
    'fastighetsbeteckning' => 'SAKNAS 9:9',
    'identity_source' => ['fastighetsbeteckning' => ['src' => 'manual', 'value' => $missMark, 'address' => '', 'at' => '2026-10-01']],
    'address' => 'Titelvägen 3, Teststad',
    'ort' => 'Teststad',
    'owners' => [[
        'name' => 'Gammal Ägare',
        'personnummer' => $oldPnr,
        'address' => 'Titelvägen 3',
        'andel' => '1/1',
        'lagfart' => '',
    ]],
    'medsokande' => [],
    'pris' => 15000,
    'steps' => [],
]);
$buttonMiss = jeab_order_fastighet_refresh($store, ['order_id' => 880614, 'dry' => 0]);
sf_assert((int) ($buttonMiss['written'] ?? 1) === 0, 'knappen skriver inte när ingen träff har beteckningen');
sf_assert((string) (jeab_purchase_get(880614)['owners'][0]['name'] ?? '') === 'Gammal Ägare', 'knappen lämnar ägarna när beteckningen saknas');
sf_assert((string) (jeab_purchase_get(880614)['fastighetsbeteckning'] ?? '') === 'SAKNAS 9:9', 'knappen lämnar beteckningen när ingen träff matchar');

jeab_purchase_put([
    'reminder_id' => 880613,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Gammal Ägare',
    'huvudsokande' => 'Gammal Ägare',
    'personnummer' => $oldPnr,
    'fastighetsbeteckning' => 'Testby 4:2',
    'objektidentitet' => 'obj-42',
    'identity_source' => ['fastighetsbeteckning' => ['src' => 'manual', 'value' => $mark, 'address' => '', 'at' => '2026-10-01']],
    'address' => 'Titelvägen 3, Teststad',
    'ort' => 'Teststad',
    'owners' => [[
        'name' => 'Gammal Ägare',
        'personnummer' => $oldPnr,
        'address' => 'Titelvägen 3',
        'andel' => '1/1',
        'lagfart' => '',
    ]],
    'medsokande' => [],
    'pris' => 15000,
    'steps' => [],
]);
$GLOBALS['SF_LM_CALLS'] = [];
$probe = jeab_ordrar_fastighet_probe($store, ['order_id' => 880613, 'dry' => 0]);
$lm = jeab_ordrar_lm_lookup_run($store, ['order_id' => 880613, 'dry' => 1]);
$probeQuery = (string) ($probe['queries'][0]['query'] ?? '');
sf_assert(($probe['dry'] ?? 0) === 1 && ($probe['written'] ?? 1) === 0, 'proben skriver inget');
sf_assert(str_contains($probeQuery, 'Titelvägen') && !str_contains($probeQuery, '4:2'), 'proben söker adressen, fick ' . $probeQuery);
sf_assert((string) ($lm['query'] ?? '') === $probeQuery, 'lm_lookup och proben använder samma sökväg');
sf_assert(!str_contains(implode('|', $GLOBALS['SF_LM_CALLS']), 'obj-42'), 'omhämtningen söker adressen som Manuell order, utan sparat objekt-id');
sf_assert((string) ($lm['owners'][0]['name'] ?? '') === 'Ny Ägare', 'lm_lookup visar ägarnamnet');
sf_assert(!array_key_exists('personnummer', (array) ($lm['owners'][0] ?? [])), 'lm_lookup har inget personnummerfält');
sf_no_pnr((string) json_encode($lm, JSON_UNESCAPED_UNICODE), 'lm_lookup-svaret saknar personnummer');
sf_no_pnr((string) json_encode($probe, JSON_UNESCAPED_UNICODE), 'proben saknar personnummer');
sf_assert((string) (jeab_purchase_get(880613)['fastighetsbeteckning'] ?? '') === 'Testby 4:2', 'lm_lookup i torrt läge lämnar beteckningen');

sf_assert($mails === [], 'inget mejl');
sf_assert(jeab_store()->listAllBookings() === [], 'ingen bokning');
sf_assert(!is_file(jeab_sms_sent_path()), 'inget sms');
sf_assert((int) ($GLOBALS['JEAB_FORTNOX_WRITES'] ?? 0) === 0, 'ingen Fortnox-skrivning');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
sf_assert(str_contains($hook, "=== 'ordrar.set_fastighet'") && str_contains($hook, 'jeab_ordrar_set_fastighet_dispatch'), 'hook.php tar ordrar.set_fastighet');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$start = strpos($src, 'function jeab_ordrar_set_fastighet_run(');
$end = strpos($src, 'function jeab_ordrar_set_fastighet_dispatch(');
sf_assert($start !== false && $end !== false && $end > $start, 'funktionen finns');
$body = substr($src, (int) $start, (int) $end - (int) $start);
foreach (['/create_booking\s*\(/', '/\w*fortnox\w*\s*\(/i', '/sms_send\s*\(/', '/system_mail\w*\s*\(/', '/notify\w*\s*\(/'] as $bad) {
    sf_assert(preg_match($bad, $body) !== 1, 'set_fastighet anropar inte ' . $bad);
}
sf_assert(str_contains($body, 'jeab_fastighet_undo_add'), 'gamla värden sparas som ångring före skrivning');

echo "ordrar set_fastighet: ok\n";
