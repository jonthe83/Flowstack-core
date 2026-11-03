<?php

declare(strict_types=1);

/**
 * Vunnen påminnelse kopierar personnummer, medsökande, huvudsökande
 * och fastighetsbeteckning till ordern så Bekräfta visar dem direkt.
 */

$tmp = sys_get_temp_dir() . '/jeab-reminder-identity-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
$_GET['co'] = 'jarfalla';
$_GET['stop'] = 'bekrafta';

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function id_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

jeab_ordrar_external_live(false);

$note = implode("\n", [
    'Vill ha batteri.',
    'Personnummer: 19480315-1237',
    'Huvudsökande: Sten Testsson',
    'Medsökande: Eva Testsson 19520101-1238',
    'Medsökande: Bo Testsson, 19600101-1239',
    'Fastighetsbeteckning: Testö 1:23',
]);

$path = $tmp . '/store.json';
$store = new JeabJsonStore($path);
$lead = jeab_create_reminder($store, 'Ljusteröhuvudsväg 23', '2026-10-02 00:00:00', $note, null, [
    'customer' => 'Sten Testsson',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
    'address' => 'Ljusteröhuvudsväg 23, Ljusterö',
    'email' => 'sten@example.com',
    'phone' => '0701234567',
]);
$leadId = (int) ($lead['id'] ?? 0);
id_assert($leadId > 0, 'påminnelsen skapades');

$started = jeab_purchase_start($store, $leadId, '');
id_assert(($started['huvudsokande'] ?? '') === 'Sten Testsson', 'huvudsökande kopieras från påminnelsen, fick ' . (string) ($started['huvudsokande'] ?? ''));
id_assert(($started['personnummer'] ?? '') === '19480315-1237', 'personnummer kopieras från anteckningen');
id_assert(($started['fastighetsbeteckning'] ?? '') === 'TESTÖ 1:23', 'fastighetsbeteckning kopieras från anteckningen, fick ' . (string) ($started['fastighetsbeteckning'] ?? ''));
id_assert(($started['huvudsokande_address'] ?? '') === 'Ljusteröhuvudsväg 23, Ljusterö', 'adressen följer med till huvudsökande');
$med = jeab_ordrar_people_list($started['medsokande'] ?? []);
id_assert(count($med) === 2, 'båda medsökande kopieras, fick ' . count($med));
id_assert(($med[0]['name'] ?? '') === 'Eva Testsson', 'första medsökande heter Eva Testsson');
id_assert(($med[0]['personnummer'] ?? '') === '19520101-1238', 'första medsökandes personnummer');
id_assert(($med[1]['name'] ?? '') === 'Bo Testsson', 'andra medsökande heter Bo Testsson');
id_assert(($med[1]['personnummer'] ?? '') === '19600101-1239', 'andra medsökandes personnummer');
id_assert(jeab_ordrar_identity_missing($started) === [], 'Fortnox-varningen är tom när påminnelsen har uppgifterna');
id_assert(($started['postnummer'] ?? '') === '', 'postnummer hämtas inte ur adressraden vid start');

$suggest = jeab_ordrar_confirm_suggest($started, $lead);
id_assert(($suggest['huvudsokande'] ?? '') === 'Sten Testsson', 'Bekräfta behåller sparad huvudsökande utan uppslag');
id_assert(($suggest['personnummer'] ?? '') === '19480315-1237', 'Bekräfta visar personnumret från ordern');
id_assert(($suggest['fastighetsbeteckning'] ?? '') === 'TESTÖ 1:23', 'Bekräfta visar fastighetsbeteckningen från ordern');
id_assert(count(jeab_ordrar_people_list($suggest['medsokande'] ?? [])) === 2, 'Bekräfta visar medsökande');
$cards = jeab_ordrar_owner_cards($suggest);
id_assert(count($cards) === 3, 'ägarkorten är huvudsökande plus två medsökande, fick ' . count($cards));
$cardNames = array_map(static fn (array $card): string => (string) ($card['name'] ?? ''), $cards);
id_assert(in_array('Eva Testsson', $cardNames, true), 'medsökande syns bland ägarkorten');
id_assert(in_array('Sten Testsson', $cardNames, true), 'huvudsökande syns bland ägarkorten');

$wiped = jeab_ordrar_apply_suggest([
    'huvudsokande' => 'Sten Testsson',
    'personnummer' => '19480315-1237',
    'fastighetsbeteckning' => 'TESTÖ 1:23',
    'medsokande' => $med,
], [
    'lookup_empty' => true,
    'name_matched' => true,
    'match_first' => 'Sten',
    'huvudsokande' => 'Annan Person',
    'personnummer' => '19000101-1234',
    'fastighetsbeteckning' => 'ANNAN 9:9',
    'medsokande' => [['name' => 'Utokad', 'personnummer' => '19000101-9999']],
]);
id_assert(($wiped['huvudsokande'] ?? '') === 'Sten Testsson', 'påminnelsens huvudsökande skrivs inte över av tomt uppslag');
id_assert(($wiped['personnummer'] ?? '') === '19480315-1237', 'påminnelsens personnummer skrivs inte över');
id_assert(($wiped['fastighetsbeteckning'] ?? '') === 'TESTÖ 1:23', 'påminnelsens fastighetsbeteckning skrivs inte över');
id_assert((($wiped['medsokande'][0]['name'] ?? '')) === 'Eva Testsson', 'påminnelsens medsökande skrivs inte över');

$alias = jeab_ordrar_apply_reminder_identity([], [
    'customer_name' => 'Ada Alias',
    'address' => 'Aliasvägen 2',
    'pnr' => '197001011234',
    'fastighet' => 'alias 4:5',
    'medsökande' => [[
        'namn' => 'Bo Alias',
        'pnr' => '198001011235',
    ]],
]);
id_assert(($alias['huvudsokande'] ?? '') === 'Ada Alias', 'pnr-alias ger huvudsökande från kundnamnet');
id_assert(($alias['personnummer'] ?? '') === '19700101-1234', 'pnr mappas till personnummer');
id_assert(($alias['fastighetsbeteckning'] ?? '') === 'ALIAS 4:5', 'fastighet mappas till fastighetsbeteckning');
id_assert(($alias['medsokande'][0]['name'] ?? '') === 'Bo Alias', 'namn-alias ger medsökande');
id_assert(($alias['medsokande'][0]['personnummer'] ?? '') === '19800101-1235', 'pnr-alias ger medsökandes personnummer');

$kept = jeab_ordrar_apply_reminder_identity([
    'huvudsokande' => 'Redan Sparad',
    'personnummer' => '19121212-1212',
    'fastighetsbeteckning' => 'SPARAD 1:1',
    'medsokande' => [['name' => 'Redan Med', 'personnummer' => '19101010-1010']],
], [
    'customer_name' => 'Ny Kund',
    'pnr' => '19480315-1237',
    'fastighet' => 'NY 2:2',
    'medsokande' => [['name' => 'Ny Med', 'personnummer' => '19520101-1238']],
]);
id_assert(($kept['huvudsokande'] ?? '') === 'Redan Sparad', 'sparad huvudsökande lämnas orörd');
id_assert(($kept['personnummer'] ?? '') === '19121212-1212', 'sparat personnummer lämnas orört');
id_assert(($kept['fastighetsbeteckning'] ?? '') === 'SPARAD 1:1', 'sparad fastighetsbeteckning lämnas orörd');
id_assert(($kept['medsokande'][0]['name'] ?? '') === 'Redan Med', 'sparad medsökande lämnas orörd');

$nameOnlyLead = jeab_create_reminder($store, 'Bara namn', '2026-10-02 00:00:00', 'ingen beteckning', null, [
    'customer' => 'Karin Endast',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
    'address' => 'Endastgatan 1, Ljusterö',
]);
$nameOnly = jeab_purchase_start($store, (int) $nameOnlyLead['id'], '');
id_assert(($nameOnly['huvudsokande'] ?? '') === 'Karin Endast', 'kundnamnet blir huvudsökande även utan personnummer');
id_assert(($nameOnly['personnummer'] ?? '') === '', 'personnummer hittas inte på när det saknas');
id_assert(($nameOnly['fastighetsbeteckning'] ?? '') === '', 'fastighetsbeteckning hittas inte på när den saknas');
$nameSuggest = jeab_ordrar_confirm_suggest($nameOnly, $nameOnlyLead);
id_assert(($nameSuggest['huvudsokande'] ?? '') === 'Karin Endast', 'Bekräfta visar kundnamnet som huvudsökande');

$prepared = jeab_ordrar_prepare_open_identity($nameOnly, $nameOnlyLead, true);
id_assert(trim((string) ($prepared['identity_filled_at'] ?? '')) === '', 'avstängt uppslag stämplar inte ett försök');
id_assert(($prepared['huvudsokande'] ?? '') === 'Karin Endast', 'förberedelsen behåller huvudsökande');

$open = $started;
$company = 'jarfalla';
$reminder = $lead;
$error = null;
$flash = null;
$html = require dirname(__DIR__) . '/src/pages/ordrar.php';
id_assert(is_string($html) && str_contains($html, 'Bekräfta'), 'Bekräfta ritas');
id_assert(str_contains($html, 'Sten Testsson'), 'Bekräfta visar huvudsökande');
id_assert(str_contains($html, '19480315-1237'), 'Bekräfta visar personnummer');
id_assert(str_contains($html, 'TESTÖ 1:23'), 'Bekräfta visar fastighetsbeteckning');
id_assert(str_contains($html, 'Eva Testsson'), 'Bekräfta visar medsökande');
id_assert(str_contains($html, 'Ljusteröhuvudsväg 23, Ljusterö'), 'Bekräfta visar adressen');
id_assert(!str_contains($html, 'Saknas för Fortnox-order'), 'varningen saknas när uppgifterna finns');

file_put_contents($tmp . '/bekrafta.html', $html);
echo "ordrar reminder identity: ok\n";
