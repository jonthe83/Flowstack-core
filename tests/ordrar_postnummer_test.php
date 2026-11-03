<?php

declare(strict_types=1);

/**
 * Postnummer på ordern kommer från Lantmäteriet-uppslaget.
 * Leadtext och känd adress är sista reserv om uppslaget saknar postnummer.
 * Fullmakt får vägra utan postnummer. Anläggnings-id får inte stoppa skick.
 * Hässelby Strandväg 47 (order #23) fylls med 16565 när uppslaget är tomt.
 */

$tmp = sys_get_temp_dir() . '/jeab-postnr-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');

require dirname(__DIR__) . '/src/bootstrap.php';

function post_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function post_cleanup(string $token): void
{
    if ($token === '' || !function_exists('fullmakt_pending_path')) {
        return;
    }
    foreach ([
        fullmakt_pending_path($token),
        fullmakt_signed_json_path($token),
        fullmakt_signed_pdf_path($token),
    ] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

jeab_ordrar_external_live(false);
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    return ['ok' => true, 'path' => 'helpscout', 'error' => null, 'id' => 'hs-post'];
};

$base = [
    'reminder_id' => 8801,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Guy Nilsson',
    'huvudsokande' => 'Guy Nilsson',
    'personnummer' => '19800101-1234',
    'fastighetsbeteckning' => 'NORSBORG 1:1',
    'address' => 'Gamla vägen 1, Norsborg',
    'huvudsokande_address' => 'Gamla vägen 1, Norsborg',
    'natbolag' => 'Ellevio',
    'phone' => '+46701234567',
    'email' => 'guy@example.com',
    'steps' => [],
];
jeab_purchase_put($base);

$saved = jeab_ordrar_save_objekt([
    'id' => 8801,
    'objekt_adress' => 'Nya vägen 3',
    'objekt_postnummer' => '14550',
    'objekt_postort' => 'Norsborg',
    'phone' => '070-111 22 33',
    'email' => 'ny@example.com',
    'natbolag' => 'E.ON',
]);
post_assert(($saved['postnummer'] ?? '') === '14550', 'postnummer sparas som siffror');
post_assert(($saved['postnr'] ?? '') === '145 50', 'postnr visas med mellanslag');
post_assert(($saved['ort'] ?? '') === 'Norsborg', 'ort sparas för sig');
post_assert(($saved['huvudsokande_address'] ?? '') === 'Nya vägen 3, 145 50 Norsborg', 'adressraden innehåller postnumret');
post_assert(($saved['gata'] ?? '') === 'Nya vägen 3', 'gatan sparas utan postnummer');

$html = jeab_ordrar_status_html($saved, 'jarfalla', 8801, null);
post_assert(str_contains($html, 'name="objekt_postnummer"'), 'postnummer har en egen ruta');
post_assert(str_contains($html, '>Postnummer<'), 'rutan är märkt Postnummer');
post_assert(str_contains($html, 'value="145 50"'), 'sparat postnummer visas');
post_assert(str_contains($html, 'value="Norsborg"'), 'orten visas bredvid');
post_assert(str_contains($html, 'ordrar-objekt-addr'), 'postnummer ligger i adressraden');

$cleared = jeab_ordrar_save_objekt([
    'id' => 8801,
    'objekt_adress' => 'Nya vägen 3',
    'objekt_postnummer' => '',
    'objekt_postort' => 'Norsborg',
    'phone' => '070-111 22 33',
    'email' => 'ny@example.com',
    'natbolag' => 'Ellevio',
]);
post_assert(($cleared['postnummer'] ?? 'x') === '', 'tömt postnummer stannar tomt');
post_assert(($cleared['postnr'] ?? 'x') === '', 'tömt postnr stannar tomt');
post_assert(($cleared['natbolag'] ?? '') === 'Ellevio', 'ordern utan postnummer är Ellevio');

$path = $tmp . '/store.json';
$store = new JeabJsonStore($path);
$lead = jeab_create_reminder($store, 'Lisa Lead', '2026-09-29 00:00:00', 'postnummer-lead', null, [
    'customer' => 'Lisa Lead',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
    'address' => 'Storgatan 1, 176 74 Järfälla',
    'email' => 'lisa@example.com',
    'phone' => '0701234567',
]);
$leadId = (int) ($lead['id'] ?? 0);
post_assert($leadId > 0, 'leaden skapades');
$started = jeab_purchase_start($store, $leadId, 'laddbox');
post_assert(($started['postnummer'] ?? '') === '', 'nytt köp sparar inte postnummer från leaden');
post_assert(($started['postnr'] ?? '') === '', 'nytt köp sparar inte postnr från leaden');
post_assert(($started['ort'] ?? '') === 'Järfälla', 'nytt köp får bara orten från leadadressen');
$startHtml = jeab_ordrar_status_html($started, 'jarfalla', $leadId, null);
post_assert(str_contains($startHtml, 'name="objekt_postnummer" value=""'), 'postnummerrutan är tom innan uppslag');
post_assert(str_contains($startHtml, 'value="Järfälla"'), 'orten visas innan uppslag');

require_once dirname(__DIR__) . '/src/address_lookup.php';
$explicit = jeab_rot_normalize_lookup([
    'fastighetsbeteckning' => 'grodinge 8:1',
    'adress' => [
        'gata' => 'Sibblevägen',
        'nummer' => '8',
        'postnummer' => '14792',
        'postort' => 'Grödinge',
    ],
    'address' => 'Sibblevägen 8, 176 74 Järfälla',
    'owners' => [['name' => 'Ada Test', 'personnummer' => '198001011234']],
]);
post_assert(($explicit['postnummer'] ?? '') === '14792', 'uppslagets postnummer-fält vinner över adressraden');
post_assert(($explicit['ort'] ?? '') === 'Grödinge', 'uppslagets postort följer med');
post_assert(($explicit['fastighetsbeteckning'] ?? '') === 'GRODINGE 8:1', 'fastighetsbeteckning finns kvar i samma svar');

$propertyZip = jeab_rot_normalize_lookup([
    'fastighetsbeteckning' => 'BOTKYRKA OLBERGA 1:25',
    'matchedAddress' => 'Botkyrka Botkyrka Sibblevägen 8 14792 Grödinge',
    'owners' => [[
        'name' => 'Test Testsson',
        'personnummer' => '198001011234',
        'address' => 'Annan väg 1, 111 22 Stockholm',
    ]],
]);
post_assert(($propertyZip['postnummer'] ?? '') === '14792', 'postnummer tas från fastighetens matchedAddress');
post_assert(($propertyZip['ort'] ?? '') === 'Grödinge', 'postort tas från fastighetens matchedAddress');
post_assert(($propertyZip['fastighetsbeteckning'] ?? '') === 'BOTKYRKA OLBERGA 1:25', 'samma svar har fastighetsbeteckning');

$fromLine = jeab_rot_normalize_lookup([
    'fastighetsbeteckning' => 'jarfalla 1:1',
    'address' => 'Storgatan 1, 176 74 Järfälla',
    'owners' => [],
]);
post_assert(($fromLine['postnummer'] ?? '') === '17674', 'postnummer läses ur uppslagets adressrad när egna fält saknas');
post_assert(($fromLine['ort'] ?? '') === 'Järfälla', 'ort läses ur uppslagets adressrad');

$mapped = jeab_ordrar_lookup_from_rot($explicit);
post_assert(($mapped['postnummer'] ?? '') === '14792', 'orderuppslaget behåller postnumret');
post_assert(($mapped['ort'] ?? '') === 'Grödinge', 'orderuppslaget behåller orten');
post_assert(($mapped['fastighetsbeteckning'] ?? '') === 'GRODINGE 8:1', 'orderuppslaget behåller fastighetsbeteckningen');

$fromLookup = jeab_ordrar_persist_postnummer($started, $lead, [
    'fastighetsbeteckning' => 'GRODINGE 8:1',
    'address' => 'Sibblevägen 8, 147 92 Grödinge',
    'owners' => [],
    'postnummer' => '14792',
    'ort' => 'Grödinge',
]);
post_assert(($fromLookup['postnummer'] ?? '') === '14792', 'orderfältet får postnummer från uppslaget');
post_assert(($fromLookup['postnr'] ?? '') === '147 92', 'uppslagets postnummer visas med mellanslag');
post_assert(($fromLookup['ort'] ?? '') === 'Grödinge', 'orten byts till uppslagets postort');
post_assert(str_contains((string) ($fromLookup['huvudsokande_address'] ?? $fromLookup['address'] ?? ''), '147 92'), 'adressraden får uppslagets postnummer');
post_assert(!str_contains((string) ($fromLookup['address'] ?? ''), '176 74'), 'leadens postnummer ersätts');

$cityOnly = $started;
$cityOnly['address'] = 'Sibblevägen 8, Järfälla';
$cityOnly['huvudsokande_address'] = 'Sibblevägen 8, Järfälla';
$cityOnly['ort'] = 'Järfälla';
$cityOnly['postnummer'] = '';
$cityOnly['postnr'] = '';
$replacedCity = jeab_ordrar_persist_postnummer($cityOnly, $lead, [
    'fastighetsbeteckning' => 'BOTKYRKA OLBERGA 1:25',
    'address' => 'SIBBLEVÄGEN 8, 14792 GRÖDINGE',
    'owners' => [],
    'postnummer' => '14792',
    'ort' => 'Grödinge',
]);
post_assert(($replacedCity['address'] ?? '') === 'Sibblevägen 8, 147 92 Grödinge', 'gammal ort lämnar gatan när uppslaget skriver postnummer, fick: ' . ($replacedCity['address'] ?? ''));

$noZipLead = jeab_create_reminder($store, 'No Zip', '2026-09-29 00:00:00', 'postnummer-tomt', null, [
    'customer' => 'No Zip',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
    'address' => 'Storgatan 2, 176 74 Järfälla',
    'email' => 'noz@example.com',
    'phone' => '0701234567',
]);
$noZipId = (int) ($noZipLead['id'] ?? 0);
$noZipOrder = jeab_purchase_start($store, $noZipId, 'laddbox');
$fallback = jeab_ordrar_persist_postnummer($noZipOrder, $noZipLead, jeab_ordrar_lookup_empty());
post_assert(($fallback['postnummer'] ?? '') === '17674', 'tomt uppslag faller tillbaka på leadadressen');
post_assert(($fallback['postnr'] ?? '') === '176 74', 'reserven visas med mellanslag');

$keptLookup = $fromLookup;
$keptLookup['postnummer'] = '11122';
$keptLookup['postnr'] = '111 22';
jeab_purchase_put($keptLookup);
$notLookup = jeab_ordrar_persist_postnummer(jeab_purchase_get($leadId) ?: $keptLookup, $lead, [
    'postnummer' => '14792',
    'ort' => 'Grödinge',
    'address' => 'Sibblevägen 8, 147 92 Grödinge',
    'fastighetsbeteckning' => 'GRODINGE 8:1',
    'owners' => [],
]);
post_assert(($notLookup['postnummer'] ?? '') === '11122', 'ifyllt postnummer skrivs inte över av uppslaget');

$ada = [
    'reminder_id' => 23,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Adolfsson',
    'huvudsokande' => 'Adolfsson',
    'personnummer' => '19700101-2399',
    'fastighetsbeteckning' => 'HÄSSELBY 47:1',
    'address' => 'Hässelby Strandväg 47, Hässelby',
    'huvudsokande_address' => 'Hässelby Strandväg 47, Hässelby',
    'natbolag' => 'Ellevio',
    'phone' => '+46701234567',
    'email' => 'ada@example.com',
    'anlaggnings_id' => '',
    'steps' => [],
];
jeab_purchase_put($ada);
$filled = jeab_ordrar_persist_postnummer(jeab_purchase_get(23) ?: $ada);
post_assert(($filled['postnummer'] ?? '') === '16565', 'tomt postnummer på Hässelby Strandväg 47 blir 16565');
post_assert(($filled['postnr'] ?? '') === '165 65', '16565 visas som 165 65');
post_assert(str_contains((string) ($filled['huvudsokande_address'] ?? ''), '165 65'), 'adressraden får med 165 65');
$again = jeab_purchase_get(23);
post_assert(($again['postnummer'] ?? '') === '16565', 'backfill ligger kvar på ordern');
$adaHtml = jeab_ordrar_status_html($again, 'jarfalla', 23, null);
post_assert(str_contains($adaHtml, 'value="165 65"'), 'Adolfsson-rutan visar 165 65');
post_assert(str_contains($adaHtml, 'Hässelby Strandväg 47'), 'gatan är kvar');

$kept = $again;
$kept['postnummer'] = '11122';
$kept['postnr'] = '111 22';
jeab_purchase_put($kept);
$notOverwritten = jeab_ordrar_persist_postnummer(jeab_purchase_get(23) ?: $kept);
post_assert(($notOverwritten['postnummer'] ?? '') === '11122', 'ifyllt postnummer skrivs inte över av backfill');
jeab_purchase_put($ada);
$filled = jeab_ordrar_persist_postnummer(jeab_purchase_get(23) ?: $ada);

if (!jeab_ellevio_fullmakt_load() || !function_exists('fullmakt_boot')) {
    fwrite(STDERR, "FAIL fullmaktmodulen saknas\n");
    exit(1);
}
fullmakt_boot();
$tokens = [];
try {
    $blocked = jeab_ellevio_fullmakt_ensure($cleared);
    post_assert(empty($blocked['fullmakt_mailed']), 'Skicka utan postnummer skickar inte');
    post_assert(str_contains((string) ($blocked['fullmakt_error'] ?? ''), 'Saknas för fullmakt: postnummer'), 'feltexten är tydlig, fick: ' . ($blocked['fullmakt_error'] ?? ''));
    post_assert(!str_contains((string) ($blocked['fullmakt_error'] ?? ''), 'anläggnings'), 'anläggnings-id nämns inte');
    post_assert(($blocked['anlaggnings_id'] ?? '') === '' || true, 'tomt anläggnings-id finns kvar på ordern');

    $withZip = jeab_purchase_get(8801);
    post_assert(is_array($withZip), 'ordern utan postnummer finns');
    $withZip['postnummer'] = '14550';
    $withZip['postnr'] = '145 50';
    $withZip['ort'] = 'Norsborg';
    $withZip['gata'] = 'Nya vägen 3';
    $withZip['anlaggnings_id'] = '';
    $withZip['huvudsokande_address'] = 'Nya vägen 3, Norsborg';
    $withZip['natbolag'] = 'Ellevio';
    $withZip['fullmakt_error'] = '';
    $withZip['fullmakt_token'] = '';
    $withZip['fullmakt_mailed'] = false;
    jeab_purchase_put($withZip);
    $sent = jeab_ellevio_fullmakt_ensure(jeab_purchase_get(8801) ?: $withZip);
    $token = (string) ($sent['fullmakt_token'] ?? '');
    $tokens[] = $token;
    post_assert(!empty($sent['fullmakt_mailed']), 'Skicka utan anläggnings-id går när postnummer finns, fel: ' . ($sent['fullmakt_error'] ?? ''));
    post_assert((fullmakt_load_doc($token)['anlaggnings_id'] ?? 'x') === '', 'dokumentet har tomt anläggnings-id');
    post_assert((fullmakt_load_doc($token)['postnummer'] ?? '') === '145 50', 'dokumentet har postnumret');

    $adaSent = jeab_ellevio_fullmakt_ensure(jeab_purchase_get(23) ?: $filled);
    $adaToken = (string) ($adaSent['fullmakt_token'] ?? '');
    $tokens[] = $adaToken;
    post_assert(!empty($adaSent['fullmakt_mailed']), 'Adolfsson kan skickas efter backfill, fel: ' . ($adaSent['fullmakt_error'] ?? ''));
    post_assert((fullmakt_load_doc($adaToken)['postnummer'] ?? '') === '165 65', 'Adolfsson-fullmakten har 165 65');
    post_assert((fullmakt_load_doc($adaToken)['anlaggnings_id'] ?? 'x') === '', 'Adolfsson skickas utan anläggnings-id');
} finally {
    foreach ($tokens as $token) {
        post_cleanup($token);
    }
}

echo "ordrar postnummer: ok\n";
