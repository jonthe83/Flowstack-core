<?php

declare(strict_types=1);

/**
 * Omhämtning av fastighetsinfo byter inte plats på ägare 1 och 2 och
 * skriver inte om namn till Lantmäteriets längre form. En dubblett blir
 * inte en tredje sökande. ordrar.applicants_repair återställer huvudsökande
 * och tar bort dubbletten. Inget personnummer i svar eller logg.
 */

$tmp = sys_get_temp_dir() . '/jeab-applicants-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
putenv('JEAB_DISABLE_SQLITE=1');
$_GET['co'] = 'jarfalla';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

function ar_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ar_make(string $yyMMdd, string $three): string
{
    for ($check = 0; $check <= 9; $check++) {
        $raw = $yyMMdd . $three . (string) $check;
        if (jeab_norm_pnr($raw) !== '') {
            return $raw;
        }
    }
    fwrite(STDERR, "FAIL kunde inte bygga ett giltigt nummer\n");
    exit(1);
}

function ar_same(string $stored, string $raw): bool
{
    $a = jeab_norm_pnr($stored);
    $b = jeab_norm_pnr($raw);

    return $a !== '' && $b !== '' && hash_equals($a, $b);
}

function ar_leak(string $hay, string ...$secrets): void
{
    // Tolv siffror inuti ett sha räknas inte. Ett personnummer står för sig.
    $bad = preg_match('/(?<![0-9A-Fa-f])\d{12}(?![0-9A-Fa-f])|(?<![0-9A-Fa-f])\d{10}(?![0-9A-Fa-f])|\d{8}\s*[-+]\s*\d{4}|\d{6}\s*[-+]\s*\d{4}/', $hay) === 1;
    foreach ($secrets as $secret) {
        if ($secret !== '' && str_contains($hay, $secret)) {
            $bad = true;
        }
        $norm = jeab_norm_pnr($secret);
        if ($norm !== '' && str_contains($hay, $norm)) {
            $bad = true;
        }
        $shown = function_exists('jeab_pnr_normalize') ? jeab_pnr_normalize($secret) : '';
        if ($shown !== '' && str_contains($hay, $shown)) {
            $bad = true;
        }
    }
    ar_ok(!$bad, 'utdata saknar personnummer');
}

$pnrA = ar_make('900101', '321');
$pnrB = ar_make('850615', '432');
$fmtA = jeab_ordrar_format_pnr($pnrA);
$fmtB = jeab_ordrar_format_pnr($pnrB);
$secrets = [$pnrA, $pnrB, $fmtA, $fmtB];

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
ar_ok(str_contains($hook, "ordrar.applicants_repair"), 'hooken tar ordrar.applicants_repair');
$js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/ordrar-v3.js');
ar_ok(str_contains($js, "action: 'ordrar.pick'") && str_contains($js, '.v3-kname'), 'manuellt val uppdaterar rubriken');

jeab_ordrar_external_live(false);
$store = new JeabJsonStore($tmp . '/store.json');
$GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
$undoPdo = new PDO('sqlite::memory:');
$undoPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$undoPdo->exec('CREATE TABLE settings ("key" TEXT PRIMARY KEY, value TEXT NOT NULL)');
$GLOBALS['_JEAB_FASTIGHET_UNDO_PDO'] = $undoPdo;

jeab_kontakter_mutate(static function (array &$contacts): void {
    $contacts['ar_ada'] = [
        'id' => 'ar_ada',
        'street' => 'Byggargatan 14',
        'postal_code' => '17760',
        'city' => 'Teststad',
        'fastighetsbeteckning' => 'TESTSTAD BYGGAREN 1:1',
        'company' => 'jarfalla',
        'owners' => [
            ['name' => 'Ada Test', 'personnummer' => '', 'contact' => true, 'email' => 'ar_ada@example.test', 'phone' => ''],
            ['name' => 'Bo Test', 'personnummer' => '', 'contact' => false, 'email' => '', 'phone' => ''],
        ],
        'created_at' => '2026-10-24 08:00:00',
        'updated_at' => '2026-10-24 08:00:00',
    ];
});

jeab_purchase_put([
    'reminder_id' => 88014,
    'kind' => 'sol',
    'company' => 'jarfalla',
    'name' => 'Ada Test',
    'huvudsokande' => 'Ada Test',
    'personnummer' => $fmtA,
    'address' => 'Byggargatan 14, 177 60 Teststad',
    'gata' => 'Byggargatan 14',
    'ort' => 'Teststad',
    'fastighetsbeteckning' => 'TESTSTAD BYGGAREN 1:1',
    'contact_id' => 'ar_ada',
    'owners' => [
        ['name' => 'Ada Test', 'personnummer' => $fmtA, 'address' => 'Byggargatan 14', 'andel' => '1/2', 'lagfart' => ''],
        ['name' => 'Bo Test', 'personnummer' => $fmtB, 'address' => 'Byggargatan 14', 'andel' => '1/2', 'lagfart' => ''],
    ],
    'medsokande' => [
        ['name' => 'Bo Test', 'personnummer' => $fmtB, 'address' => 'Byggargatan 14', 'andel' => '1/2', 'lagfart' => ''],
    ],
    'kalkyl' => ['sokande' => 2, 'b14' => 10, 'b3' => 1, 'b15' => 0, 'laddare' => 0],
]);

$row = jeab_purchase_get(88014);
ar_ok(is_array($row), 'order 88014 finns');
$suggest = [
    'lookup_empty' => false,
    'fastighetsbeteckning' => 'TESTSTAD BYGGAREN 1:1',
    'huvudsokande' => 'Bo Maria Test',
    'personnummer' => $fmtB,
    'owners' => [
        ['name' => 'Bo Maria Test', 'personnummer' => $fmtB, 'andel' => '1/2', 'address' => 'Byggargatan 14'],
        ['name' => 'Ada Maria Test', 'personnummer' => $fmtA, 'andel' => '1/2', 'address' => 'Byggargatan 14'],
        ['name' => 'Ada Maria Test', 'personnummer' => $fmtA, 'andel' => '1/2', 'address' => 'Byggargatan 14'],
    ],
];
$newPeople = jeab_fastighet_suggest_people($suggest);
$diff = jeab_fastighet_diff($row, $suggest, jeab_fastighet_stored_people($row), $newPeople);
ar_ok(!isset($diff['owners']), 'samma personer ger ingen ägardiff trots längre namn och omvänd ordning');
$forced = $diff;
$forced['owners'] = ['old' => [], 'new' => []];
$applied = jeab_fastighet_apply_order_fields($row, $forced, $suggest, $newPeople);
$names = array_map(static fn(array $o): string => (string) ($o['name'] ?? ''), is_array($applied['owners'] ?? null) ? $applied['owners'] : []);
ar_ok($names === ['Ada Test', 'Bo Test'], 'omhämtningen behåller plats 1 och 2');
ar_ok(count($applied['owners'] ?? []) === 2, 'en dubblett från omhämtningen blir inte en tredje ägare');
ar_ok((string) ($applied['huvudsokande'] ?? '') === 'Ada Test', 'huvudsökande byts inte till medägaren');
ar_ok(count($applied['medsokande'] ?? []) === 1, 'medsökande är bara den andra personen');
ar_ok((string) ($applied['medsokande'][0]['name'] ?? '') === 'Bo Test', 'medsökande behåller det korta namnet');
ar_ok(ar_same((string) ($applied['owners'][0]['personnummer'] ?? ''), $pnrA), 'plats 1 behåller sitt nummer');
ar_ok(ar_same((string) ($applied['owners'][1]['personnummer'] ?? ''), $pnrB), 'plats 2 behåller sitt nummer');
ar_ok(jeab_offert_sokande_from_purchase($applied) === 2, 'kalkylens sökande stannar på 2');
ar_ok((int) ($applied['kalkyl']['sokande'] ?? 0) === 2, 'sparad sökande skrivs inte upp');

$bothInMed = [
    'owners' => [
        ['name' => 'Ada Test', 'personnummer' => $fmtA],
        ['name' => 'Bo Test', 'personnummer' => $fmtB],
    ],
    'medsokande' => [
        ['name' => 'Ada Maria Test', 'personnummer' => $fmtA],
        ['name' => 'Bo Maria Test', 'personnummer' => $fmtB],
    ],
];
ar_ok(jeab_offert_sokande_from_purchase($bothInMed) === 2, 'båda i medsökande räknas inte som 3');
$cards = jeab_ordrar_owner_cards([
    'huvudsokande' => 'Ada Test',
    'personnummer' => $fmtA,
    'owners' => [
        ['name' => 'Bo Maria Test', 'personnummer' => $fmtB],
        ['name' => 'Ada Maria Test', 'personnummer' => $fmtA],
    ],
]);
ar_ok(count($cards) === 2, 'kort namn mot längre namn lägger inte till ett tredje kort');
$huvudCards = array_values(array_filter($cards, static fn(array $card): bool => !empty($card['huvud'])));
ar_ok(count($huvudCards) === 1 && str_contains((string) ($huvudCards[0]['name'] ?? ''), 'Ada'), 'huvudkortet är Ada');

$denied = jeab_ordrar_applicants_repair_dispatch($store, ['order_id' => 88014]);
ar_ok(($denied['ok'] ?? true) === false && (int) ($denied['status'] ?? 0) === 401, 'hooken kräver token');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
jeab_kontakter_mutate(static function (array &$contacts) use ($fmtB, $pnrA): void {
    $contacts['ar_ada']['owners'] = [
        ['name' => 'Bo Test', 'personnummer' => $fmtB, 'contact' => true, 'email' => 'ar_ada@example.test', 'phone' => ''],
        ['name' => 'Ada Test', 'personnummer' => '', 'contact' => false, 'email' => '', 'phone' => ''],
        ['name' => 'Ada Maria Test', 'personnummer' => $pnrA, 'contact' => false, 'email' => '', 'phone' => ''],
    ];
    $contacts['ar_ada']['huvudsokande'] = '';
});
jeab_purchase_put([
    'reminder_id' => 88014,
    'kind' => 'sol',
    'company' => 'jarfalla',
    'name' => 'Bo Test',
    'huvudsokande' => 'Bo Test',
    'huvudsokande_manual' => false,
    'personnummer' => $fmtB,
    'address' => 'Byggargatan 14, 177 60 Teststad',
    'gata' => 'Byggargatan 14',
    'ort' => 'Teststad',
    'fastighetsbeteckning' => 'TESTSTAD BYGGAREN 1:1',
    'contact_id' => 'ar_ada',
    'owners' => [
        ['name' => 'Bo Test', 'personnummer' => $fmtB, 'address' => 'Byggargatan 14', 'andel' => '1/2', 'lagfart' => ''],
        ['name' => 'Ada Test', 'personnummer' => '', 'address' => 'Byggargatan 14', 'andel' => '1/2', 'lagfart' => ''],
        ['name' => 'Ada Maria Test', 'personnummer' => $fmtA, 'address' => 'Byggargatan 14', 'andel' => '1/2', 'lagfart' => ''],
    ],
    'medsokande' => [
        ['name' => 'Ada Test', 'personnummer' => '', 'address' => 'Byggargatan 14', 'andel' => '1/2', 'lagfart' => ''],
        ['name' => 'Bo Test', 'personnummer' => $fmtB, 'address' => 'Byggargatan 14', 'andel' => '1/2', 'lagfart' => ''],
    ],
    'kalkyl' => ['sokande' => 3, 'b14' => 10, 'b3' => 1, 'b15' => 0, 'laddare' => 0],
]);
ar_ok(jeab_ordrar_name(jeab_purchase_get(88014)) === 'Bo Test', 'rubriken följer kontaktens flaggade ägare innan rättningen');

$dry = jeab_ordrar_applicants_repair($store, ['order_id' => 88014]);
$dryJson = (string) json_encode($dry, JSON_UNESCAPED_UNICODE);
ar_ok(($dry['ok'] ?? false) === true && (int) ($dry['dry'] ?? 0) === 1 && (int) ($dry['written'] ?? 1) === 0, 'utan dry är svaret torrt');
ar_ok((int) ($dry['applicant_count'] ?? 0) === 3, 'torrkörningen visar 3 sökande');
ar_ok((string) ($dry['huvudsokande'] ?? '') === 'Bo Test', 'torrkörningen visar medägaren som huvudsökande');
$drySlots = is_array($dry['slots'] ?? null) ? $dry['slots'] : [];
ar_ok(count($drySlots) === 3 && !empty($drySlots[2]['duplicate']), 'tredje platsen är en dubblett');
ar_ok(($drySlots[1]['has_pnr'] ?? true) === false && ($drySlots[2]['has_pnr'] ?? false) === true, 'listan säger bara om numret finns');
ar_leak($dryJson, ...$secrets);
ar_ok((int) (jeab_purchase_get(88014)['kalkyl']['sokande'] ?? 0) === 3, 'torrkörningen skriver inte');

$missing = jeab_ordrar_applicants_repair($store, []);
ar_ok(($missing['ok'] ?? true) === false, 'utan order_id blir det fel');

$wet = jeab_ordrar_applicants_repair($store, [
    'order_id' => 88014,
    'dry' => 0,
    'huvudsokande' => 'Ada Test',
    'remove_duplicate' => 1,
]);
$wetJson = (string) json_encode($wet, JSON_UNESCAPED_UNICODE);
ar_ok(($wet['ok'] ?? false) === true && (int) ($wet['written'] ?? 0) === 1, 'rättningen skrivs');
ar_ok((int) ($wet['applicant_count'] ?? 0) === 2, 'efter rättning är det 2 sökande');
ar_ok((string) ($wet['huvudsokande'] ?? '') === 'Ada Test', 'svaret visar Ada som huvudsökande');
ar_leak($wetJson, ...$secrets);
$fixed = jeab_purchase_get(88014);
$fixedNames = array_map(static fn(array $o): string => (string) ($o['name'] ?? ''), is_array($fixed['owners'] ?? null) ? $fixed['owners'] : []);
ar_ok($fixedNames === ['Bo Test', 'Ada Test'], 'platserna ligger kvar och dubbletten är borta');
ar_ok(ar_same((string) ($fixed['owners'][1]['personnummer'] ?? ''), $pnrA), 'Adas nummer finns kvar på platsen');
ar_ok(ar_same((string) ($fixed['owners'][0]['personnummer'] ?? ''), $pnrB), 'Bos nummer ligger kvar');
ar_ok((int) ($fixed['kalkyl']['sokande'] ?? 0) === 2, 'sparad sökande går tillbaka från 3 till 2');
ar_ok(jeab_ordrar_name($fixed) === 'Ada Test', 'rubriken läser huvudsökande efter rättningen');
$contact = jeab_kontakt_get('ar_ada');
$contactNames = array_map(static fn(array $o): string => (string) ($o['name'] ?? ''), is_array($contact['owners'] ?? null) ? $contact['owners'] : []);
ar_ok($contactNames === ['Bo Test', 'Ada Test'], 'kontaktens dubblett är borta och ordningen ligger kvar');
ar_ok(ar_same((string) ($contact['owners'][1]['personnummer'] ?? ''), $pnrA), 'numret följde med till Adas plats');
ar_ok((string) ($contact['huvudsokande'] ?? '') === 'Ada Test', 'kontakten minns huvudsökande');

$log = jeab_fastighet_log_text();
$errLog = (string) @file_get_contents($tmp . '/app-errors.jsonl');
ar_leak($log . "\n" . $errLog, ...$secrets);

$would = jeab_ordrar_applicants_repair($store, ['order_id' => 88014, 'undo' => (string) ($wet['batch_id'] ?? '')]);
ar_ok((int) ($would['would_undo'] ?? 0) === 1 && (int) ($would['written'] ?? 1) === 0, 'ångring utan dry skriver inte');
ar_ok((int) (jeab_purchase_get(88014)['kalkyl']['sokande'] ?? 0) === 2, 'order ligger kvar före ångring');

$undone = jeab_ordrar_applicants_repair($store, [
    'order_id' => 88014,
    'dry' => 0,
    'undo' => (string) ($wet['batch_id'] ?? ''),
]);
$undoJson = (string) json_encode($undone, JSON_UNESCAPED_UNICODE);
ar_ok(($undone['ok'] ?? false) === true && (int) ($undone['undone'] ?? 0) === 1, 'ångringen gick');
ar_ok((int) ($undone['applicant_count'] ?? 0) === 3, 'ångringen lägger tillbaka tre platser');
ar_leak($undoJson, ...$secrets);
$back = jeab_purchase_get(88014);
ar_ok(count($back['owners'] ?? []) === 3, 'tre ägarrader är tillbaka');
ar_ok((int) ($back['kalkyl']['sokande'] ?? 0) === 3, 'sökande 3 är tillbaka');
ar_ok(jeab_ordrar_name($back) === 'Bo Test', 'rubriken är medägaren igen efter ångring');
ar_leak(jeab_fastighet_log_text(), ...$secrets);

$picked = jeab_ordrar_save_pick([
    'id' => 88014,
    'huvudsokande' => 'Ada Test',
]);
ar_ok(is_array($picked), 'manuellt val sparas');
ar_ok(jeab_ordrar_name(jeab_purchase_get(88014)) === 'Ada Test', 'rubriken följer det manuella valet');
ar_ok((string) (jeab_kontakt_get('ar_ada')['huvudsokande'] ?? '') === 'Ada Test', 'kontakten fick det manuella valet');
ar_leak(jeab_fastighet_log_text() . "\n" . (string) @file_get_contents($tmp . '/app-errors.jsonl'), ...$secrets);

echo "OK  klart\n";
