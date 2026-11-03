<?php

declare(strict_types=1);

/**
 * Ny adress i Objekt => nytt Lantmäteriet-uppslag på den nya adressen.
 * Tomma och automatiskt ifyllda fält (från gamla adressen) fylls/ersätts.
 * Handskrivna värden och gamla värden utan markering lämnas orörda.
 * Oförändrad adress gör inget uppslag. Ingen bokning, mejl, sms eller Fortnox.
 * Inget riktigt nätverksanrop: LantmaterietAPI är en stubbfil som räknar anrop.
 */

$tmp = sys_get_temp_dir() . '/jeab-relookup-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';

function rl_assert(bool $ok, string $message): void
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
        $GLOBALS['RL_LM_CALLS'][] = (string) $addressText;
        if (stripos($addressText, 'Rättgatan 5') !== false) {
            $this->data = [
                'fastighetsbeteckning' => 'Rätt 1:5',
                'matchedAddress' => 'Rättgatan 5',
                'postnummer' => '17834',
                'postort' => 'Ekerö',
                'owners' => [
                    ['name' => 'Sten Håkansson', 'personnummer' => '195501011234', 'address' => 'Rättgatan 5, 178 34 Ekerö', 'andel' => '1/1'],
                ],
            ];
            return true;
        }
        if (stripos($addressText, 'Annangatan 9') !== false) {
            $this->data = [
                'fastighetsbeteckning' => 'Annan 2:9',
                'matchedAddress' => 'Annangatan 9',
                'postnummer' => '16565',
                'postort' => 'Hässelby',
                'owners' => [
                    ['name' => 'Sten Håkansson', 'personnummer' => '196001015678', 'address' => 'Annangatan 9, 165 65 Hässelby', 'andel' => '1/1'],
                ],
            ];
            return true;
        }
        if (stripos($addressText, 'Grannväg 3') !== false) {
            $this->data = [
                'fastighetsbeteckning' => 'Granne 4:4',
                'matchedAddress' => 'Grannväg 3',
                'postnummer' => '17541',
                'postort' => 'Järfälla',
                'owners' => [
                    ['name' => 'Helt Annan Person', 'personnummer' => '197001019999', 'address' => 'Grannväg 3, 175 41 Järfälla', 'andel' => '1/1'],
                ],
            ];
            return true;
        }
        return false;
    }
}
PHP);
$GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES'] = [$rotDir . '/data_scraping.php'];
$GLOBALS['RL_LM_CALLS'] = [];
jeab_ordrar_external_live(true);

$calls = static fn(): int => count($GLOBALS['RL_LM_CALLS']);

// Ordern som Sten Håkanssons: felstavad adress, uppslaget hittade inget.
$id = 777001;
jeab_purchase_put([
    'reminder_id' => $id,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Sten Håkansson',
    'huvudsokande' => '',
    'personnummer' => '',
    'fastighetsbeteckning' => '',
    'address' => 'Rattgtan 5, Ekerö',
    'huvudsokande_address' => 'Rattgtan 5, Ekerö',
    'gata' => 'Rattgtan 5',
    'postnr' => '',
    'postnummer' => '',
    'ort' => 'Ekerö',
    'phone' => '+46701234567',
    'email' => 'sten@example.com',
    'natbolag' => 'Ellevio',
    'steps' => [],
]);
$form = static fn(array $row, array $over = []): array => array_merge([
    'id' => (int) $row['reminder_id'],
    'objekt_adress' => jeab_ordrar_status_address_lines($row)['street'],
    'objekt_postnummer' => jeab_ordrar_status_address_lines($row)['postnummer'],
    'objekt_postort' => jeab_ordrar_status_address_lines($row)['place'],
    'phone' => '0701234567',
    'email' => (string) ($row['email'] ?? ''),
    'natbolag' => 'Ellevio',
    'objekt_personnummer' => (string) ($row['personnummer'] ?? ''),
    'objekt_fastighetsbeteckning' => (string) ($row['fastighetsbeteckning'] ?? ''),
], $over);

// 1. Adressen rättas: nytt uppslag på nya adressen fyller tomma fält.
$row = (array) jeab_purchase_get($id);
$n0 = $calls();
$saved = jeab_ordrar_save_objekt($form($row, ['objekt_adress' => 'Rättgatan 5']));
rl_assert($calls() > $n0, 'ändrad adress gör nytt uppslag');
rl_assert(str_contains(implode(' ', array_slice($GLOBALS['RL_LM_CALLS'], $n0)), 'Rättgatan 5'), 'uppslaget söker på den nya adressen');
rl_assert(($saved['fastighetsbeteckning'] ?? '') === 'RÄTT 1:5', 'tom fastighetsbeteckning fylls från nya adressen');
rl_assert(($saved['personnummer'] ?? '') === '19550101-1234', 'tomt personnummer fylls med matchad ägare');
rl_assert(($saved['postnummer'] ?? '') === '17834' && ($saved['postnr'] ?? '') === '178 34', 'tomt postnummer fylls');
rl_assert(jeab_ordrar_identity_source($saved, 'fastighetsbeteckning') === 'lookup', 'fastighet markeras som uppslag');
rl_assert(jeab_ordrar_identity_source($saved, 'personnummer') === 'lookup', 'personnummer markeras som uppslag');
rl_assert(jeab_ordrar_identity_source($saved, 'postnummer') === 'lookup', 'postnummer markeras som uppslag');
rl_assert(str_contains((string) ($saved['identity_source']['fastighetsbeteckning']['address'] ?? ''), 'Rättgatan 5'), 'markeringen säger vilken adress värdet kom från');
rl_assert(trim((string) ($saved['address_edited_at'] ?? '')) !== '', 'adressändringen stämplas');
$stored = (array) jeab_purchase_get($id);
rl_assert(($stored['personnummer'] ?? '') === '19550101-1234' && ($stored['fastighetsbeteckning'] ?? '') === 'RÄTT 1:5', 'ifyllt ligger kvar efter omläsning');

// 2. Oförändrad adress: inget uppslag.
$n1 = $calls();
$same = jeab_ordrar_save_objekt($form($stored, ['email' => 'ny@example.com']));
rl_assert($calls() === $n1, 'oförändrad adress gör inget uppslag');
rl_assert(($same['email'] ?? '') === 'ny@example.com' && ($same['fastighetsbeteckning'] ?? '') === 'RÄTT 1:5', 'övriga fält sparas som vanligt');
rl_assert(jeab_ordrar_identity_source($same, 'personnummer') === 'lookup', 'oförändrat värde behåller sin uppslagsmarkering');

// 3. Ny adress igen: automatiska värden från gamla adressen ersätts.
$n2 = $calls();
$moved = jeab_ordrar_save_objekt($form($same, ['objekt_adress' => 'Annangatan 9', 'objekt_postort' => 'Hässelby']));
rl_assert($calls() > $n2, 'ny adress gör nytt uppslag igen');
rl_assert(($moved['fastighetsbeteckning'] ?? '') === 'ANNAN 2:9', 'automatisk fastighet ersätts');
rl_assert(($moved['personnummer'] ?? '') === '19600101-5678', 'automatiskt personnummer ersätts');
rl_assert(($moved['postnummer'] ?? '') === '16565', 'automatiskt postnummer ersätts');

// 4. Handskrivna värden skrivs aldrig över. Personnummer skrivs på ägarkortet, inte i Objekt.
$typedFast = jeab_ordrar_save_objekt($form($moved, [
    'objekt_fastighetsbeteckning' => 'Egen 7:7',
]));
rl_assert(jeab_ordrar_identity_source($typedFast, 'fastighetsbeteckning') === 'manual', 'inskriven fastighet markeras manuellt');
$typed = $typedFast;
$typed['personnummer'] = '19821212-1212';
$typed['huvudsokande'] = 'Sten Håkansson';
$typed['owners'] = [['name' => 'Sten Håkansson', 'personnummer' => '19821212-1212', 'address' => 'Annangatan 9']];
$typed = jeab_ordrar_mark_identity_source($typed, 'personnummer', 'manual', 'hand');
$typed = jeab_purchase_put($typed);
rl_assert(jeab_ordrar_identity_source($typed, 'personnummer') === 'manual', 'personnummer på ägarkortet kan markeras manuellt');
$back = jeab_ordrar_save_objekt($form($typed, ['objekt_adress' => 'Rättgatan 5', 'objekt_postort' => 'Ekerö']));
rl_assert(($back['personnummer'] ?? '') === '19821212-1212', 'handskrivet personnummer ligger kvar efter adressbyte');
rl_assert(($back['fastighetsbeteckning'] ?? '') === 'Egen 7:7', 'handskriven fastighet ligger kvar efter adressbyte');
rl_assert(($back['postnummer'] ?? '') === '17834', 'automatiskt postnummer ersätts ändå');

// 4b. Inskrivet postnummer är handskrivet.
$zipTyped = jeab_ordrar_save_objekt($form($back, ['objekt_postnummer' => '178 30']));
rl_assert(jeab_ordrar_identity_source($zipTyped, 'postnummer') === 'manual', 'inskrivet postnummer markeras manuellt');
$zipKept = jeab_ordrar_save_objekt($form($zipTyped, ['objekt_adress' => 'Annangatan 9', 'objekt_postort' => 'Hässelby']));
rl_assert(($zipKept['postnummer'] ?? '') === '17830', 'handskrivet postnummer ligger kvar');

// 5. Gamla värden utan markering räknas som manuella.
$oldId = 777002;
jeab_purchase_put([
    'reminder_id' => $oldId,
    'kind' => 'laddbox',
    'name' => 'Sten Håkansson',
    'huvudsokande' => 'Sten Håkansson',
    'personnummer' => '19400101-0000',
    'fastighetsbeteckning' => 'GAMMAL 1:1',
    'address' => 'Gamla vägen 1, 178 00 Ekerö',
    'huvudsokande_address' => 'Gamla vägen 1, 178 00 Ekerö',
    'postnummer' => '17800',
    'postnr' => '178 00',
    'ort' => 'Ekerö',
    'steps' => [],
]);
$old = (array) jeab_purchase_get($oldId);
$oldMoved = jeab_ordrar_save_objekt($form($old, ['objekt_adress' => 'Rättgatan 5']));
rl_assert(($oldMoved['personnummer'] ?? '') === '19400101-0000', 'omarkerat personnummer skrivs inte över');
rl_assert(($oldMoved['fastighetsbeteckning'] ?? '') === 'GAMMAL 1:1', 'omarkerad fastighet skrivs inte över');
rl_assert(($oldMoved['postnummer'] ?? '') === '17800', 'omarkerat postnummer skrivs inte över');

// 6. Ägaren matchar inte kunden: fastighet fylls, personnummer lämnas tomt (#251-reglerna).
$nbId = 777003;
jeab_purchase_put([
    'reminder_id' => $nbId,
    'kind' => 'laddbox',
    'name' => 'Sten Håkansson',
    'personnummer' => '',
    'fastighetsbeteckning' => '',
    'address' => 'Granvag 3, Järfälla',
    'huvudsokande_address' => 'Granvag 3, Järfälla',
    'ort' => 'Järfälla',
    'steps' => [],
]);
$nb = jeab_ordrar_save_objekt($form((array) jeab_purchase_get($nbId), ['objekt_adress' => 'Grannväg 3']));
rl_assert(($nb['fastighetsbeteckning'] ?? '') === 'GRANNE 4:4', 'fastighet fylls även utan ägarmatch');
rl_assert(($nb['personnummer'] ?? '') === '', 'personnummer fylls inte när ägaren inte matchar kunden');

// 7. Ny adress utan träff (t.ex. bostadsrätt): inget ändras, inget kraschar.
$miss = jeab_ordrar_save_objekt($form((array) jeab_purchase_get($nbId), ['objekt_adress' => 'Brfgatan 1']));
rl_assert(($miss['fastighetsbeteckning'] ?? '') === 'GRANNE 4:4' && ($miss['personnummer'] ?? '') === '', 'utan träff lämnas fälten som de var');

// 8. Visat förslag som sparas oförändrat räknas som automatiskt och kan ersättas.
$sgId = 777004;
jeab_purchase_put([
    'reminder_id' => $sgId,
    'kind' => 'laddbox',
    'name' => 'Sten Håkansson',
    'huvudsokande' => 'Sten Håkansson',
    'huvudsokande_manual' => true,
    'personnummer' => '',
    'fastighetsbeteckning' => '',
    'address' => 'Rättgatan 5, Ekerö',
    'huvudsokande_address' => 'Rättgatan 5, Ekerö',
    'ort' => 'Ekerö',
    'steps' => [],
]);
$sg = (array) jeab_purchase_get($sgId);
$shown = jeab_ordrar_status_identity($sg, $sgId);
rl_assert(($shown['fastighetsbeteckning_forslag'] ?? '') === 'RÄTT 1:5', 'Objekt vet att fastigheten är ett förslag');
$html = jeab_ordrar_status_html($sg, 'jarfalla', $sgId, null);
rl_assert(str_contains($html, 'name="objekt_fastighetsbeteckning_forslag" value="RÄTT 1:5"'), 'förslaget följer med formuläret dolt');
$sgSaved = jeab_ordrar_save_objekt($form($sg, [
    'objekt_adress' => 'Annangatan 9',
    'objekt_postort' => 'Hässelby',
    'objekt_personnummer' => (string) $shown['personnummer'],
    'objekt_fastighetsbeteckning' => (string) $shown['fastighetsbeteckning'],
    'objekt_personnummer_forslag' => (string) $shown['personnummer_forslag'],
    'objekt_fastighetsbeteckning_forslag' => (string) $shown['fastighetsbeteckning_forslag'],
]));
rl_assert(($sgSaved['fastighetsbeteckning'] ?? '') === 'ANNAN 2:9', 'oförändrat förslag från gamla adressen ersätts');
rl_assert(($sgSaved['personnummer'] ?? '') === '19600101-5678', 'oförändrat pnr-förslag från gamla adressen ersätts');

// 9. Efter rättad adress söker Bekräfta/Uppdatera på orderns adress, inte leadens.
$lead = ['address' => 'Rattgtan 5, Ekerö', 'title' => 'Sten Håkansson'];
$q0 = jeab_ordrar_lookup_query($lead, ['address' => 'Rättgatan 5, 178 34 Ekerö']);
$q1 = jeab_ordrar_lookup_query($lead, ['address' => 'Rättgatan 5, 178 34 Ekerö', 'address_edited_at' => '2026-10-06 09:00:00']);
rl_assert(str_starts_with($q0, 'Rättgatan 5'), 'orderns egen gata går före leadens adress och titel');
rl_assert(str_starts_with($q1, 'Rättgatan 5'), 'rättad adress går före leadens');
$q2 = jeab_ordrar_lookup_query($lead, ['address' => 'Rättgatan 5, 178 34 Ekerö', 'objekt_saved_at' => '2026-10-05 12:00:00']);
rl_assert(str_starts_with($q2, 'Rättgatan 5'), 'adress sparad i Objekt före fixen går också före leadens');

// 10. Bekräfta: ändrat för hand blir manuellt, oförändrat förslag blir uppslag.
jeab_ordrar_external_live(false);
$cf = (array) jeab_purchase_get($nbId);
$cf['fastighetsbeteckning'] = '';
unset($cf['identity_source']);
jeab_purchase_put($cf);
$c1 = jeab_ordrar_apply_confirm_fields((array) jeab_purchase_get($nbId), ['fastighetsbeteckning' => 'GRANNE 4:4', 'fastighetsbeteckning_forslag' => 'GRANNE 4:4']);
rl_assert(jeab_ordrar_identity_source($c1, 'fastighetsbeteckning') === 'lookup', 'Bekräfta: oförändrat förslag markeras som uppslag');
$c2 = jeab_ordrar_apply_confirm_fields($c1, ['fastighetsbeteckning' => 'EGEN 5:5', 'fastighetsbeteckning_forslag' => 'GRANNE 4:4']);
rl_assert(jeab_ordrar_identity_source($c2, 'fastighetsbeteckning') === 'manual', 'Bekräfta: ändrat för hand markeras manuellt');

// 11. Inga sidoeffekter.
rl_assert($mails === [], 'inget mejl');
rl_assert(jeab_store()->listAllBookings() === [], 'ingen bokning');
rl_assert(!is_file(jeab_sms_sent_path()), 'inget sms');
foreach ([$saved, $moved, $back, $oldMoved, $nb, $sgSaved] as $r) {
    rl_assert(trim((string) ($r['fortnox_order'] ?? '')) === '' && (int) ($r['booking_id'] ?? 0) === 0, 'ingen Fortnox-order eller bokning på order ' . (int) $r['reminder_id']);
}
$src = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$start = strpos($src, 'function jeab_ordrar_relookup_after_address_change(');
$end = strpos($src, "\nfunction ", (int) $start + 10);
$body = substr($src, (int) $start, (int) $end - (int) $start);
foreach (['/create_booking\s*\(/', '/\w*fortnox\w*\s*\(/i', '/sms_send\s*\(/', '/system_mail\w*\s*\(/', '/notify\w*\s*\(/', '/purchase_put\s*\(/'] as $bad) {
    rl_assert(preg_match($bad, $body) !== 1, 'uppslaget anropar inte ' . $bad);
}

unset($GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES']);
echo "ordrar address relookup: ok\n";
