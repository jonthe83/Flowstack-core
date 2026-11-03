<?php

declare(strict_types=1);

/**
 * Ny adress / Uppdatera: hela ägarlistan från Lantmäteriet ersätter automatiska ägare.
 * Handskrivna ägare lämnas. Ägaradress följer den rättade adressen. Bekräfta visar båda ägarna.
 * Inget riktigt nätverksanrop: LantmaterietAPI är en stubbfil.
 */

$tmp = sys_get_temp_dir() . '/jeab-relookup-owners-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';

function ro_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
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
        $GLOBALS['RO_LM_CALLS'][] = (string) $addressText;
        if (stripos($addressText, 'Gnejsvägen 15A') !== false) {
            $this->data = [
                'fastighetsbeteckning' => 'Täby Roslags-Näsby 9:23',
                'matchedAddress' => 'Gnejsvägen 15A',
                'postnummer' => '18730',
                'postort' => 'Täby',
                'owners' => [
                    ['name' => 'Johan Edin', 'personnummer' => '198001011234', 'address' => 'Gnejsvägen 15A, 187 30 Täby', 'andel' => '1/2'],
                    ['name' => 'Anna Edin', 'personnummer' => '198202022345', 'andel' => '1/2'],
                ],
            ];
            return true;
        }
        if (stripos($addressText, 'Annanväg 2') !== false) {
            $this->data = [
                'fastighetsbeteckning' => 'Täby Annan 1:1',
                'matchedAddress' => 'Annanväg 2',
                'postnummer' => '18731',
                'postort' => 'Täby',
                'owners' => [
                    ['name' => 'Olle Annan', 'personnummer' => '197505051111', 'address' => 'Annanväg 2, 187 31 Täby', 'andel' => '1/1'],
                ],
            ];
            return true;
        }
        if (stripos($addressText, 'Ljusterö') !== false || stripos($addressText, 'huvuds') !== false) {
            $this->data = [
                'fastighetsbeteckning' => 'Österåker Bolby 3:166',
                'matchedAddress' => 'Ljusterö huvuds väg 23',
                'postnummer' => '18497',
                'postort' => 'Ljusterö',
                'owners' => [
                    ['name' => 'Sten Håkansson', 'personnummer' => '196007218214', 'address' => 'Ljusterö huvuds väg 23', 'andel' => '1/2'],
                    ['name' => 'Eva Håkansson', 'personnummer' => '196203034567', 'andel' => '1/2'],
                ],
            ];
            return true;
        }
        return false;
    }
}
PHP);
$GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES'] = [$rotDir . '/data_scraping.php'];
$GLOBALS['RO_LM_CALLS'] = [];
jeab_ordrar_external_live(true);

$form = static fn(array $row, array $over = []): array => array_merge([
    'id' => (int) $row['reminder_id'],
    'objekt_adress' => jeab_ordrar_status_address_lines($row)['street'],
    'objekt_postnummer' => jeab_ordrar_status_address_lines($row)['postnummer'],
    'objekt_postort' => jeab_ordrar_status_address_lines($row)['place'],
    'phone' => '0701234567',
    'email' => 'kund@example.com',
    'natbolag' => 'Ellevio',
    'objekt_personnummer' => (string) ($row['personnummer'] ?? ''),
    'objekt_fastighetsbeteckning' => (string) ($row['fastighetsbeteckning'] ?? ''),
], $over);

// Fallet Gnejsvägen: felstavad adress, Bekräfta sparade platshållaren «Johan Edin» utan pnr på gamla adressen.
$legacy = [
    'kind' => 'sol',
    'company' => 'jarfalla',
    'name' => 'Johan Edin',
    'huvudsokande' => 'Johan Edin',
    'huvudsokande_manual' => true,
    'personnummer' => '',
    'fastighetsbeteckning' => '',
    'address' => 'Genjsvägen 15A, Täby',
    'huvudsokande_address' => 'Genjsvägen 15A, Täby',
    'gata' => 'Genjsvägen 15A',
    'ort' => 'Täby',
    'owners' => [['name' => 'Johan Edin', 'personnummer' => '', 'address' => 'Genjsvägen 15A Täby', 'andel' => '', 'lagfart' => '']],
    'medsokande' => [],
    'steps' => [],
];

// 1. Adressen rättas i Objekt: hela ägarlistan från uppslaget ersätter platshållaren.
jeab_purchase_put($legacy + ['reminder_id' => 880001]);
$saved = jeab_ordrar_save_objekt($form((array) jeab_purchase_get(880001), ['objekt_adress' => 'Gnejsvägen 15A']));
$owners = jeab_ordrar_people_list($saved['owners'] ?? [], 8);
ro_assert(($saved['fastighetsbeteckning'] ?? '') === 'TÄBY ROSLAGS-NÄSBY 9:23', 'fastigheten följer nya adressen (som i #254)');
ro_assert(count($owners) === 2, 'båda ägarna från uppslaget sparas');
ro_assert(($owners[0]['name'] ?? '') === 'Johan Edin' && ($owners[0]['personnummer'] ?? '') === '19800101-1234', 'kunden matchad (#251) är Ägare 1 med personnummer');
ro_assert(($owners[1]['name'] ?? '') === 'Anna Edin' && ($owners[1]['personnummer'] ?? '') === '19820202-2345', 'Ägare 2 med personnummer');
ro_assert(($saved['personnummer'] ?? '') === '19800101-1234', 'row.personnummer är huvudsökandes');
$med = jeab_ordrar_people_list($saved['medsokande'] ?? []);
ro_assert(count($med) === 1 && ($med[0]['name'] ?? '') === 'Anna Edin', 'övriga blir medsökande');
foreach ($owners as $o) {
    ro_assert(str_contains((string) $o['address'], 'Gnejsvägen 15A') && !str_contains((string) $o['address'], 'Genjs'), 'ägaradressen följer rättade adressen: ' . $o['name']);
}
ro_assert(str_contains((string) ($med[0]['address'] ?? ''), 'Gnejsvägen 15A'), 'medsökandes adress följer rättade adressen');
ro_assert(count((array) ($saved['identity_source']['owners']['entries'] ?? [])) === 2, 'ägarposterna markeras som uppslag');

// 2. Bekräfta (utan live-uppslag) läser de nya värdena: båda ägarna, rätt adress.
jeab_ordrar_external_live(false);
$suggest = jeab_ordrar_confirm_suggest((array) jeab_purchase_get(880001), null);
$cards = jeab_ordrar_owner_cards($suggest);
ro_assert(count($cards) === 2, 'Bekräfta visar två ägarkort');
ro_assert($cards[0]['name'] === 'Johan Edin' && $cards[0]['personnummer'] === '19800101-1234' && $cards[0]['huvud'] === true, 'Ägare 1 Johan Edin, huvudsökande, med pnr');
ro_assert($cards[1]['name'] === 'Anna Edin' && $cards[1]['personnummer'] === '19820202-2345' && $cards[1]['huvud'] === false, 'Ägare 2 Anna Edin, medsökande, med pnr');
ro_assert(!str_contains($cards[0]['address'] . $cards[1]['address'], 'Genjs'), 'ingen gammal adress på korten');
$fx = jeab_ordrar_fortnox_owners((array) jeab_purchase_get(880001));
ro_assert(count($fx) === 2, 'Fortnox-ägarlistan har båda, utan dubbletter');
ro_assert(!in_array('personnummer för medsökande', jeab_ordrar_fortnox_missing((array) jeab_purchase_get(880001)), true), 'inget personnummer saknas för medsökande');
jeab_ordrar_external_live(true);

// 3. Ny adress igen: automatiska ägare ersätts med nya listan.
$moved = jeab_ordrar_save_objekt($form((array) jeab_purchase_get(880001), ['objekt_adress' => 'Annanväg 2']));
$mo = jeab_ordrar_people_list($moved['owners'] ?? [], 8);
ro_assert(count($mo) === 1 && ($mo[0]['name'] ?? '') === 'Olle Annan', 'automatiska ägare ersätts vid nästa adressbyte');

// 4. Handskriven extraperson (matchar inte LM): behålls, saknade LM-ägare läggs till.
jeab_purchase_put(array_merge($legacy, [
    'reminder_id' => 880002,
    'owners' => [['name' => 'Kalle Manuell', 'personnummer' => '19700101-1111', 'address' => 'Genjsvägen 15A Täby']],
    'medsokande' => [['name' => 'Kalle Manuell', 'personnummer' => '19700101-1111']],
]));
$man = jeab_ordrar_save_objekt($form((array) jeab_purchase_get(880002), ['objekt_adress' => 'Gnejsvägen 15A']));
$mo2 = jeab_ordrar_people_list($man['owners'] ?? [], 8);
$mo2Names = array_column($mo2, 'name');
ro_assert(in_array('Kalle Manuell', $mo2Names, true), 'handskriven extraperson behålls');
ro_assert(in_array('Johan Edin', $mo2Names, true) && in_array('Anna Edin', $mo2Names, true), 'saknade LM-ägare läggs till bredvid manuell');
$kalle = null;
foreach ($mo2 as $o) {
    if (($o['name'] ?? '') === 'Kalle Manuell') {
        $kalle = $o;
        break;
    }
}
ro_assert(is_array($kalle) && ($kalle['personnummer'] ?? '') === '19700101-1111', 'manuell pnr skrivs inte över');

// 4b. Handtillagd post + gammal auto-ägare: behåll båda, lägg till nya LM från rätt adress.
$marked = (array) jeab_purchase_get(880001);
$marked['owners'][] = ['name' => 'Tillagd Förhand', 'personnummer' => '19900909-9999', 'address' => 'Annanväg 2'];
jeab_purchase_put($marked);
$keepAdded = jeab_ordrar_save_objekt($form((array) jeab_purchase_get(880001), ['objekt_adress' => 'Gnejsvägen 15A']));
$names = array_column(jeab_ordrar_people_list($keepAdded['owners'] ?? [], 8), 'name');
ro_assert(in_array('Tillagd Förhand', $names, true), 'handtillagd post behålls');
ro_assert(in_array('Olle Annan', $names, true), 'tidigare ägare som inte matchar nya LM behålls');
ro_assert(in_array('Johan Edin', $names, true) && in_array('Anna Edin', $names, true), 'nya LM-ägare läggs till');

// 4c. Omarkerad post med pnr som matchar LM till namnet: behåll kortet orört, lägg till syskon.
jeab_purchase_put(array_merge($legacy, [
    'reminder_id' => 880003,
    'owners' => [['name' => 'Johan Edin', 'personnummer' => '19800101-0000', 'address' => 'Genjsvägen 15A Täby']],
]));
$pn = jeab_ordrar_save_objekt($form((array) jeab_purchase_get(880003), ['objekt_adress' => 'Gnejsvägen 15A']));
$pnOwners = jeab_ordrar_people_list($pn['owners'] ?? [], 8);
ro_assert(count($pnOwners) === 2, 'matchande omarkerad + saknad LM → två ägare, fick ' . count($pnOwners));
ro_assert(($pnOwners[0]['name'] ?? '') === 'Johan Edin' && ($pnOwners[0]['personnummer'] ?? '') === '19800101-0000', 'Ägare 1: pnr skrivs inte över');
ro_assert(($pnOwners[1]['name'] ?? '') === 'Anna Edin', 'Ägare 2 från LM');
$addedSigs = (array) ($pn['identity_source']['owners']['entries'] ?? []);
ro_assert(count($addedSigs) >= 1, 'nytillagd LM-ägare markeras lookup');
ro_assert(!jeab_ordrar_owner_entry_auto($pn, $pnOwners[0], []), 'befintligt omarkerat kort omklassas inte');

// 4d. Omarkerad post med annat namn: behålls, LM läggs till.
jeab_purchase_put(array_merge($legacy, [
    'reminder_id' => 880004,
    'owners' => [['name' => 'Bertil Granne', 'personnummer' => '', 'address' => 'Genjsvägen 15A Täby']],
]));
$other = jeab_ordrar_save_objekt($form((array) jeab_purchase_get(880004), ['objekt_adress' => 'Gnejsvägen 15A']));
$otherNames = array_column(jeab_ordrar_people_list($other['owners'] ?? [], 8), 'name');
ro_assert(in_array('Bertil Granne', $otherNames, true), 'omarkerad post med annat namn behålls');
ro_assert(in_array('Johan Edin', $otherNames, true) && in_array('Anna Edin', $otherNames, true), 'LM-ägare läggs till bredvid Bertil');

// 4e. Håkansson: ensam sparad med pnr, ingen identity_source.owners, matchar en av två LM.
jeab_purchase_put([
    'reminder_id' => 880006,
    'kind' => 'sol',
    'company' => 'jarfalla',
    'name' => 'Sten Håkansson',
    'huvudsokande' => 'Sten Håkansson',
    'personnummer' => '19600721-8214',
    'fastighetsbeteckning' => 'ÖSTERÅKER BOLBY 3:166',
    'address' => 'Ljusterö huvuds väg 23, 184 97 Ljusterö',
    'huvudsokande_address' => 'Ljusterö huvuds väg 23 Ljusterö',
    'gata' => 'Ljusterö huvuds väg 23',
    'owners' => [['name' => 'Sten Håkansson', 'personnummer' => '19600721-8214', 'address' => 'Ljusterö huvuds väg 23 Ljusterö']],
    'medsokande' => [],
    'kalkyl' => ['b14' => 0, 'b3' => 2, 'sokande' => 1, 'b15' => 1, 'laddare' => 0],
    'steps' => [],
]);
$GLOBALS['RO_LM_CALLS'] = [];
$hak = jeab_ordrar_sync_lookup_owners((array) jeab_purchase_get(880006), null);
$hakOwners = jeab_ordrar_people_list($hak['owners'] ?? [], 8);
ro_assert(count($hakOwners) === 2, 'Håkansson Uppdatera: två ägare, fick ' . count($hakOwners));
ro_assert(($hakOwners[0]['name'] ?? '') === 'Sten Håkansson' && ($hakOwners[0]['personnummer'] ?? '') === '19600721-8214', 'Sten behålls orörd');
ro_assert(($hakOwners[1]['name'] ?? '') === 'Eva Håkansson', 'Eva läggs till från LM');
$medHak = jeab_ordrar_people_list($hak['medsokande'] ?? []);
ro_assert(count($medHak) === 1 && ($medHak[0]['name'] ?? '') === 'Eva Håkansson', 'Eva blir medsökande');
ro_assert(jeab_ordrar_owner_entry_auto($hak, $hakOwners[1], []), 'Eva markeras lookup');
ro_assert(!jeab_ordrar_owner_entry_auto($hak, $hakOwners[0], []), 'Sten omklassas inte');
ro_assert((int) ($hak['kalkyl']['sokande'] ?? 0) === 2, 'Uppdatera höjer sparad sökande till 2 när medsökande tillkommer, fick ' . (int) ($hak['kalkyl']['sokande'] ?? 0));
$hakInput = jeab_offert_input_from_purchase($hak, 'sol');
ro_assert((int) $hakInput['sokande'] === 2, 'Antal sökande i kalkylen blir 2');
$beforeHak = json_encode((array) jeab_purchase_get(880006));
jeab_ordrar_sync_lookup_owners((array) jeab_purchase_get(880006), null);
ro_assert(json_encode((array) jeab_purchase_get(880006)) === $beforeHak, 'andra Uppdatera på Håkansson ändrar inget');

jeab_purchase_put([
    'reminder_id' => 880007,
    'kind' => 'sol',
    'company' => 'jarfalla',
    'name' => 'Sten Håkansson',
    'huvudsokande' => 'Sten Håkansson',
    'personnummer' => '19600721-8214',
    'fastighetsbeteckning' => 'ÖSTERÅKER BOLBY 3:166',
    'address' => 'Ljusterö huvuds väg 23, 184 97 Ljusterö',
    'huvudsokande_address' => 'Ljusterö huvuds väg 23 Ljusterö',
    'gata' => 'Ljusterö huvuds väg 23',
    'owners' => [['name' => 'Sten Håkansson', 'personnummer' => '19600721-8214', 'address' => 'Ljusterö huvuds väg 23 Ljusterö']],
    'medsokande' => [],
    'kalkyl' => ['sokande' => 4, 'b14' => 0, 'b3' => 2, 'b15' => 0, 'laddare' => 0],
    'steps' => [],
]);
$keptHigh = jeab_ordrar_sync_lookup_owners((array) jeab_purchase_get(880007), null);
ro_assert(count(jeab_ordrar_people_list($keptHigh['medsokande'] ?? [])) === 1, 'högre sökande-testet får medsökande');
ro_assert((int) ($keptHigh['kalkyl']['sokande'] ?? 0) === 4, 'manuellt högre antal sökande sänks inte');

// 5. Uppdatera på order där adressen redan rättats före fixen: ägarlistan fylls.
jeab_purchase_put(array_merge($legacy, [
    'reminder_id' => 880005,
    'address' => 'Gnejsvägen 15A, 187 30 Täby',
    'huvudsokande_address' => 'Gnejsvägen 15A, 187 30 Täby',
    'gata' => 'Gnejsvägen 15A',
    'postnummer' => '18730',
    'postnr' => '187 30',
    'fastighetsbeteckning' => 'TÄBY ROSLAGS-NÄSBY 9:23',
    'objekt_saved_at' => '2026-10-06 11:00:00',
    'kalkyl' => ['sokande' => 1, 'b14' => 18, 'b3' => 4, 'b15' => 1, 'laddare' => 0],
]));
$GLOBALS['RO_LM_CALLS'] = [];
$synced = jeab_ordrar_sync_lookup_owners((array) jeab_purchase_get(880005), ['address' => 'Genjsvägen 15A, Täby', 'title' => 'Johan Edin']);
ro_assert(str_starts_with(jeab_ordrar_lookup_query(['address' => 'Genjsvägen 15A, Täby', 'title' => 'Johan Edin'], (array) jeab_purchase_get(880005)), 'Gnejsvägen 15A'), 'Uppdatera söker på rättade adressen, inte leadens');
$st = (array) jeab_purchase_get(880005);
ro_assert(count(jeab_ordrar_people_list($st['owners'] ?? [], 8)) === 2, 'Uppdatera sparar båda ägarna');
ro_assert(($st['personnummer'] ?? '') === '19800101-1234', 'Uppdatera sparar huvudsökandes personnummer');
ro_assert(count(jeab_ordrar_people_list($st['medsokande'] ?? [])) === 1, 'Uppdatera sparar medsökande');
ro_assert((int) ($st['kalkyl']['sokande'] ?? 0) === 2, 'automatisk ägarlista höjer sparad sökande från 1 till 2');
$before = json_encode($st);
jeab_ordrar_sync_lookup_owners($st, ['address' => 'Genjsvägen 15A, Täby', 'title' => 'Johan Edin']);
ro_assert(json_encode((array) jeab_purchase_get(880005)) === $before, 'andra Uppdatera ändrar inget');

// 6. Bekräfta-sparning: kort från uppslaget förblir automatiska, inskrivet blir handskrivet.
jeab_ordrar_external_live(false);
$row6 = (array) jeab_purchase_get(880005);
$sigs = array_map('jeab_ordrar_owner_sig', jeab_ordrar_people_list($row6['owners'], 8));
$post = [
    'owner_name' => ['Johan Edin', 'Anna Edin'],
    'owner_pnr' => ['19800101-1234', '19820202-2345'],
    'owner_address' => [$row6['owners'][0]['address'], $row6['owners'][1]['address']],
    'owner_forslag' => $sigs,
];
$c1 = jeab_ordrar_apply_confirm_fields($row6, $post);
foreach (jeab_ordrar_people_list($c1['owners'], 8) as $p) {
    ro_assert(jeab_ordrar_owner_entry_auto($c1, $p, []), 'Bekräfta: oförändrat kort förblir automatiskt: ' . $p['name']);
}
$post['owner_pnr'][1] = '19820202-0000';
$c2 = jeab_ordrar_apply_confirm_fields($c1, $post);
$p2 = jeab_ordrar_people_list($c2['owners'], 8)[1];
ro_assert(!jeab_ordrar_owner_entry_auto($c2, $p2, []), 'Bekräfta: ändrat kort blir handskrivet');
$fs = jeab_ordrar_owner_forslag($row6, $suggest, jeab_ordrar_owner_cards(jeab_ordrar_confirm_suggest($row6, null)));
ro_assert(count($fs) === 2, 'Bekräfta-formuläret får dolda förslag för båda korten');

// 7. Inga sidoeffekter.
ro_assert($mails === [], 'inget mejl');
ro_assert(jeab_store()->listAllBookings() === [], 'ingen bokning');
ro_assert(!is_file(jeab_sms_sent_path()), 'inget sms');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
foreach (['jeab_ordrar_apply_lookup_owners', 'jeab_ordrar_sync_lookup_owners', 'jeab_ordrar_apply_lookup_identity'] as $fn) {
    $start = strpos($src, 'function ' . $fn . '(');
    $end = strpos($src, "\nfunction ", (int) $start + 10);
    $body = substr($src, (int) $start, (int) $end - (int) $start);
    foreach (['/create_booking\s*\(/', '/\w*fortnox\w*\s*\(/i', '/sms_send\s*\(/', '/system_mail\w*\s*\(/', '/notify\w*\s*\(/'] as $bad) {
        ro_assert(preg_match($bad, $body) !== 1, $fn . ' anropar inte ' . $bad);
    }
}

unset($GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES']);
echo "ordrar relookup owners: ok\n";
