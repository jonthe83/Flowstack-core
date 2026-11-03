<?php

declare(strict_types=1);

/**
 * Lantmäteriet-träff och ordersparning skriver personnummer på matchande
 * ägarplats och fastighetsbeteckning på kontakten, via jeab_kontakt_save_input.
 * Ett annat ifyllt nummer skrivs inte över. Loggen får inget personnummer.
 */

$tmp = sys_get_temp_dir() . '/jeab-lm-contact-' . getmypid();
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

$lmPurchase = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$lmPurchase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $lmPurchase;
jeab_purchases_store_ensure($lmPurchase);

function lm_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function lm_make(string $yyMMdd, string $three): string
{
    for ($check = 0; $check <= 9; $check++) {
        $raw = $yyMMdd . $three . (string) $check;
        $norm = jeab_norm_pnr($raw);
        if ($norm !== '') {
            return $raw;
        }
    }
    fwrite(STDERR, "FAIL kunde inte bygga ett giltigt nummer\n");
    exit(1);
}

function lm_same(string $stored, string $raw): bool
{
    $a = jeab_norm_pnr($stored);
    $b = jeab_norm_pnr($raw);

    return $a !== '' && $b !== '' && hash_equals($a, $b);
}

function lm_leak(string $hay, string ...$secrets): void
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
    lm_ok(!$bad, 'utdata saknar personnummer');
}

/** @param array<string, mixed> $owner */
function lm_contact(string $id, string $street, array $owner, string $fast = ''): void
{
    jeab_kontakter_mutate(static function (array &$contacts) use ($id, $street, $owner, $fast): void {
        $contacts[$id] = [
            'id' => $id,
            'street' => $street,
            'postal_code' => '17760',
            'city' => 'Teststad',
            'fastighetsbeteckning' => $fast,
            'company' => 'jarfalla',
            'owners' => [$owner + ['contact' => true, 'email' => $id . '@example.test', 'phone' => '']],
            'created_at' => '2026-10-24 08:00:00',
            'updated_at' => '2026-10-24 08:00:00',
        ];
    });
}

$pnrA = lm_make('900101', '123');
$pnrB = lm_make('850615', '234');
$pnrC = lm_make('780303', '111');
$secrets = [$pnrA, $pnrB, $pnrC];

$src = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$start = strpos($src, 'function jeab_ordrar_sync_lm_contact(');
$end = strpos($src, 'function jeab_ordrar_sync_lm_contact_apply(');
$head = $start !== false && $end !== false ? substr($src, $start, $end - $start) : '';
lm_ok(str_contains($src, 'function jeab_ordrar_sync_lm_contact_apply(') && str_contains($src, 'jeab_kontakt_save_input($input)'), 'synken går genom kontaktsparningen');
lm_ok(str_contains($src, 'jeab_ordrar_sync_lm_contact($row)') && str_contains($src, 'jeab_ordrar_sync_lm_contact($saved)'), 'ordersparning och uppslag anropar synken');
lm_ok(!str_contains($head, 'error_log') && !str_contains($head, 'echo '), 'synken skriver inte ut numret');

jeab_ordrar_external_live(false);

lm_contact('lm_ada', 'Adagatan 4', ['name' => 'Ada Test', 'personnummer' => '']);
jeab_purchase_put([
    'reminder_id' => 77001,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Ada Test',
    'huvudsokande' => 'Ada Test',
    'contact_id' => 'lm_ada',
    'address' => 'Adagatan 4, 177 60 Teststad',
    'gata' => 'Adagatan 4',
    'ort' => 'Teststad',
    'postnummer' => '17760',
    'fastighetsbeteckning' => 'TESTSTAD ADA 1:1',
    'personnummer' => $pnrA,
    'owners' => [[
        'name' => 'Ada Test',
        'personnummer' => $pnrA,
        'address' => 'Adagatan 4',
    ]],
    'medsokande' => [[
        'name' => 'Bo Test',
        'personnummer' => $pnrB,
    ]],
]);
$wrote = jeab_ordrar_sync_lm_contact((array) jeab_purchase_get(77001));
lm_ok($wrote === true, 'tomma platser skrivs');
$ada = jeab_kontakt_get('lm_ada');
lm_ok(is_array($ada) && (string) ($ada['fastighetsbeteckning'] ?? '') === 'TESTSTAD ADA 1:1', 'fastigheten hamnar på kontakten');
lm_ok(is_array($ada) && (string) ($ada['street'] ?? '') === 'Adagatan 4', 'kontaktens gata ligger kvar');
lm_ok(is_array($ada) && lm_same((string) ($ada['owners'][0]['personnummer'] ?? ''), $pnrA), 'plats 1 får numret');
lm_ok(is_array($ada) && (string) ($ada['owners'][1]['name'] ?? '') === 'Bo Test', 'plats 2 skapas för andra ägaren');
lm_ok(is_array($ada) && lm_same((string) ($ada['owners'][1]['personnummer'] ?? ''), $pnrB), 'plats 2 får numret');
lm_ok((string) ($ada['owners'][0]['email'] ?? '') === 'lm_ada@example.test', 'e-posten ligger kvar');

lm_contact('lm_cia', 'Ciagatan 5', ['name' => 'Cia Test', 'personnummer' => $pnrC], 'CIA 1:1');
jeab_purchase_put([
    'reminder_id' => 77002,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Cia Test',
    'huvudsokande' => 'Cia Test',
    'contact_id' => 'lm_cia',
    'address' => 'Ciagatan 5, 177 60 Teststad',
    'gata' => 'Ciagatan 5',
    'ort' => 'Teststad',
    'fastighetsbeteckning' => 'CIA 9:9',
    'owners' => [[
        'name' => 'Cia Test',
        'personnummer' => $pnrA,
    ]],
]);
$blocked = jeab_ordrar_sync_lm_contact((array) jeab_purchase_get(77002));
lm_ok($blocked === true, 'fastigheten skrivs även när numret krockar');
$cia = jeab_kontakt_get('lm_cia');
lm_ok(is_array($cia) && lm_same((string) ($cia['owners'][0]['personnummer'] ?? ''), $pnrC), 'ett annat nummer ligger kvar');
lm_ok(is_array($cia) && (string) ($cia['fastighetsbeteckning'] ?? '') === 'CIA 9:9', 'ny fastighet skrivs');
$log = (string) @file_get_contents($tmp . '/app-errors.jsonl');
lm_ok(str_contains($log, 'Ägarplatsen har ett annat personnummer. Inget skrevs över.'), 'felloggen tar konflikten');
lm_leak('sha256:ab012345678901cdef', 'inte-ett-nummer');
lm_ok(
    preg_match('/(?<![0-9A-Fa-f])\d{12}(?![0-9A-Fa-f])/', 'sha256:ab012345678901cdef före 199001011234 efter') === 1,
    'ett fristående personnummer räknas fortfarande'
);
lm_leak($log, ...$secrets);

lm_contact('lm_dan', 'Dangatan 6', ['name' => 'Dan Test', 'personnummer' => '']);
jeab_purchase_put([
    'reminder_id' => 77003,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Dan Test',
    'huvudsokande' => 'Dan Test',
    'contact_id' => 'lm_dan',
    'address' => 'Dangatan 6, 177 60 Teststad',
    'gata' => 'Dangatan 6',
    'ort' => 'Teststad',
    'postnummer' => '17760',
    'owners' => [[
        'name' => 'Dan Test',
        'personnummer' => '',
        'address' => 'Dangatan 6',
    ]],
]);
$saved = jeab_ordrar_save_pick([
    'id' => 77003,
    'huvudsokande' => 'Dan Test',
    'fastighetsbeteckning' => 'DAN 2:2',
    'owner_name' => ['Dan Test'],
    'owner_pnr' => [$pnrB],
    'owner_address' => ['Dangatan 6'],
]);
lm_ok(is_array($saved) && (string) ($saved['fastighetsbeteckning'] ?? '') === 'DAN 2:2', 'ordersparningen tar fastigheten');
$dan = jeab_kontakt_get('lm_dan');
lm_ok(is_array($dan) && (string) ($dan['fastighetsbeteckning'] ?? '') === 'DAN 2:2', 'ordersparningen skriver fastigheten på kontakten');
lm_ok(is_array($dan) && lm_same((string) ($dan['owners'][0]['personnummer'] ?? ''), $pnrB), 'ordersparningen skriver plats 1');

$rotDir = $tmp . '/rot';
@mkdir($rotDir, 0775, true);
file_put_contents($rotDir . '/data_scraping.php', <<<'PHP'
<?php
class LantmaterietAPI {
    public $data = [];
    public function search($addressText, $uuid = '') {
        if (stripos((string) $addressText, 'Evagatan') === false) {
            return false;
        }
        $this->data = [[
            'fastighetsbeteckning' => 'TESTSTAD EVA 3:3',
            'matchedAddress' => 'Evagatan 7, 177 60 Teststad',
            'postnummer' => '17760',
            'postort' => 'Teststad',
            'agare' => [
                'namn' => 'Eva Test',
                'personnummer' => (string) ($GLOBALS['LM_FETCH_PNR'] ?? ''),
                'andel' => '1/1',
            ],
        ]];
        return true;
    }
}
PHP);
$GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES'] = [$rotDir . '/data_scraping.php'];
$GLOBALS['LM_FETCH_PNR'] = $pnrA;
$undoPdo = new PDO('sqlite::memory:');
$undoPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$undoPdo->exec('CREATE TABLE settings ("key" TEXT PRIMARY KEY, value TEXT NOT NULL)');
$GLOBALS['_JEAB_FASTIGHET_UNDO_PDO'] = $undoPdo;
lm_contact('lm_eva', 'Evagatan 7', ['name' => 'Eva Test', 'personnummer' => '']);
jeab_purchase_put([
    'reminder_id' => 77004,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Eva Test',
    'huvudsokande' => 'Eva Test',
    'contact_id' => 'lm_eva',
    'address' => 'Evagatan 7, 177 60 Teststad',
    'gata' => 'Evagatan 7',
    'ort' => 'Teststad',
    'postnummer' => '17760',
    'owners' => [[
        'name' => 'Eva Test',
        'personnummer' => '',
        'address' => 'Evagatan 7',
    ]],
]);
$store = jeab_store();
jeab_ordrar_external_live(true);
$fetched = jeab_order_fastighet_refresh($store, ['order_id' => 77004, 'dry' => 0]);
lm_ok(($fetched['ok'] ?? false) === true && ($fetched['written'] ?? 0) === 1, 'hämtknappen skriver');
$evaOrder = jeab_purchase_get(77004);
$eva = jeab_kontakt_get('lm_eva');
lm_ok(is_array($evaOrder) && (string) ($evaOrder['fastighetsbeteckning'] ?? '') !== '', 'hämtningen fyller orderns fastighet');
lm_ok(is_array($eva) && (string) ($eva['fastighetsbeteckning'] ?? '') === (string) ($evaOrder['fastighetsbeteckning'] ?? ''), 'hämtningen skriver fastigheten på kontakten');
lm_ok(is_array($eva) && lm_same((string) ($eva['owners'][0]['personnummer'] ?? ''), $pnrA), 'hämtningen skriver plats 1');
lm_ok((string) ($eva['owners'][0]['name'] ?? '') === 'Eva Test', 'hämtningen behåller ägarnamnet');

$again = (string) @file_get_contents($tmp . '/app-errors.jsonl');
lm_leak($again, ...$secrets);

echo "ordrar lm contact slots: ok\n";
