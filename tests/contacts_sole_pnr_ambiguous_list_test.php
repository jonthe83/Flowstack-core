<?php

declare(strict_types=1);

/**
 * kontakter.pnr_ambiguous_list läser bara.
 * Antalet ska vara samma som sole_verify pnr_name_ambiguous.
 * Påhittade personnummer får inte synas i svaret, inte maskade och inte i loggen.
 */

$tmp = sys_get_temp_dir() . '/jeab-pnr-list-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$errLog = $tmp . '/php-error.log';
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
$_ENV['JEAB_DISABLE_SQLITE'] = '0';
putenv('CONTACTS_SOLE');
ini_set('log_errors', '1');
ini_set('error_log', $errLog);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function list_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @return array<string, string> */
function list_tree(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $out[$file->getPathname()] = hash('sha256', (string) file_get_contents($file->getPathname()));
    }
    ksort($out);

    return $out;
}

function list_pnr_pattern(string $text): bool
{
    if (preg_match('/\d{12}/', $text) === 1) {
        return true;
    }
    if (preg_match('/(?<!\d)\d{10}(?!\d)/', $text) === 1) {
        return true;
    }
    if (preg_match('/(?<!\d)\d{8}\s*[-+]\s*\d{4}(?!\d)/', $text) === 1) {
        return true;
    }
    if (preg_match('/(?<!\d)\d{6}\s*[-+]\s*\d{4}(?!\d)/', $text) === 1) {
        return true;
    }

    return false;
}

/** @param array<string, mixed> $value @param list<string> $keys */
function list_keys(array $value, array &$keys): void
{
    foreach ($value as $key => $item) {
        $keys[] = (string) $key;
        if (is_array($item)) {
            list_keys($item, $keys);
        }
    }
}

$root = dirname(__DIR__);
$hookSrc = (string) file_get_contents($root . '/hook.php');
$soleSrc = (string) file_get_contents($root . '/src/contacts_sole.php');
list_ok(str_contains($hookSrc, "'kontakter.pnr_ambiguous_list'"), 'hook.php tar kontakter.pnr_ambiguous_list');
$hookFrom = strpos($hookSrc, "['kontakter.sole'");
$hookTo = strpos($hookSrc, 'jeab_contacts_sole_dispatch');
$hookGate = ($hookFrom !== false && $hookTo !== false && $hookTo > $hookFrom) ? substr($hookSrc, $hookFrom, $hookTo - $hookFrom) : '';
list_ok(str_contains($hookGate, "'kontakter.pnr_ambiguous_list'") && str_contains($hookGate, "'kontakter.pnr_ambiguous_resolve'"), 'listan ligger i samma grind som övriga kontakter.sole-hookar');
$dispatchFrom = strpos($soleSrc, 'function jeab_contacts_sole_dispatch(');
$dispatchBody = $dispatchFrom !== false ? substr($soleSrc, $dispatchFrom) : '';
list_ok(str_contains($dispatchBody, 'jeab_inkorg_hook_authorized') && strpos($dispatchBody, 'jeab_inkorg_hook_authorized') < strpos($dispatchBody, "'kontakter.pnr_ambiguous_list'"), 'tokengrinden ligger före listan');
$listFrom = strpos($soleSrc, 'function jeab_contacts_sole_pnr_ambiguous_list(');
$listTo = strpos($soleSrc, 'function jeab_contacts_sole_pnr_fix(');
$listBody = ($listFrom !== false && $listTo !== false && $listTo > $listFrom) ? substr($soleSrc, $listFrom, $listTo - $listFrom) : '';
list_ok($listBody !== '' && !str_contains($listBody, 'error_log') && !str_contains($listBody, 'file_put_contents') && !str_contains($listBody, 'jeab_purchase_put') && !str_contains($listBody, 'jeab_kontakter_mutate') && !str_contains($listBody, 'fwrite'), 'listan skriver inte och loggar inte');

$twelve = '199013410001';
$twelveHyphen = '19901341-0001';
$ten = '9013410003';
$tenStored = '19901341-0003';
$orderTen = '9013410005';
$otherTwelve = '199013410002';
$clearTwelve = '199013410088';
$fortnoxPnr = '199013410077';
$masked = '19901341-****';
$phone = '0701000091';
$email = 'tom.ambig@example.test';
$needles = [$twelve, $twelveHyphen, $ten, $tenStored, $orderTen, $otherTwelve, $clearTwelve, $fortnoxPnr, $masked, '19901341', '901341', '****', '•', $phone, $email];

$now = '2026-01-01 00:00:00';
$contact = static function (string $id, string $street, string $postal, string $city, array $owners, array $extra = []) use ($now): array {
    return $extra + [
        'id' => $id,
        'street' => $street,
        'street_key' => '',
        'postal_code' => $postal,
        'city' => $city,
        'owners' => $owners,
        'kind' => 'person',
        'ovrigt' => '',
        'created_at' => $now,
        'updated_at' => $now,
    ];
};
jeab_kontakter_mutate(static function (array &$contacts) use ($contact, $twelve, $ten, $clearTwelve, $masked, $phone, $email): void {
    $contacts['kt_amb_a'] = $contact('kt_amb_a', 'Ambiguagatan 1', '11122', 'Teststad', [
        ['name' => 'Ada Ambig', 'personnummer' => '', 'address' => '', 'email' => $email, 'phone' => $phone],
        ['name' => 'Bo Ambig', 'personnummer' => $twelve, 'address' => '', 'phone' => $phone],
        ['name' => '', 'personnummer' => '', 'address' => '', 'email' => $email, 'phone' => $phone],
    ], [
        'fortnox_customer' => '',
        'fortnox_customer_number' => '88',
        'fortnox_kundnummer' => 'K77',
    ]);
    $contacts['kt_amb_b'] = $contact('kt_amb_b', 'Ciagatan 2', '11122', 'Teststad', [
        ['name' => 'Cia Klar', 'personnummer' => '', 'address' => ''],
    ]);
    $contacts['kt_amb_c'] = $contact('kt_amb_c', 'Dangatan 9', '22233', 'Testort', [
        ['name' => 'Dan Dubbel', 'personnummer' => '', 'adress' => 'Dangatan 4'],
    ]);
    $contacts['kt_amb_e'] = $contact('kt_amb_e', 'Evagatan 3', '33344', 'Testby', [
        ['name' => 'Eva Mask', 'personnummer' => $masked, 'address' => ''],
    ]);
    $contacts['kt_amb_f'] = $contact('kt_amb_f', 'Fiagatan 6', '44455', 'Testby', [
        ['name' => 'Fia Hel', 'personnummer' => $clearTwelve, 'address' => 'Fiagatan 6'],
        ['name' => 'Fia Tio', 'personnummer' => $ten, 'address' => 'Tiogatan 1'],
    ]);
});
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);

$orders = [
    '93011' => [
        'reminder_id' => 93011,
        'contact_id' => 'kt_amb_a',
        'fortnox_customer' => '42',
        'fortnox_customer_number' => $twelve,
        'owners' => [
            ['name' => 'Någon Annan', 'personnummer' => $orderTen, 'address' => 'Annan gata 3'],
        ],
    ],
    '93012' => [
        'reminder_id' => 93012,
        'contact_id' => 'kt_amb_b',
        'fortnox_customer' => 'K9',
        'owners' => [
            ['name' => 'Cia Klar', 'personnummer' => $otherTwelve, 'address' => 'Ciagatan 2'],
        ],
    ],
    '93013' => [
        'reminder_id' => 93013,
        'contact_id' => 'kt_amb_c',
        'fortnox_customer' => 'K9',
        'owners' => [
            ['name' => 'Dan Dubbel', 'personnummer' => $otherTwelve, 'address' => 'Dangatan 1'],
            ['name' => 'dan  dubbel', 'personnummer' => $clearTwelve, 'address' => 'Dangatan 2'],
        ],
    ],
    '93014' => [
        'reminder_id' => 93014,
        'contact_id' => 'kt_amb_e',
        'fortnox_customer' => $fortnoxPnr,
        'owners' => [
            ['name' => 'Annan Mask', 'personnummer' => $masked, 'address' => 'Annan gata 8'],
        ],
    ],
    '93016' => [
        'reminder_id' => 93016,
        'contact_id' => 'kt_amb_f',
        'owners' => [
            ['name' => 'Någon Hel', 'personnummer' => $ten, 'address' => 'Annan gata 9'],
        ],
    ],
    '93017' => [
        'reminder_id' => 93017,
        'contact_id' => '',
        'fortnox_customer' => '55',
        'owners' => [
            ['name' => 'Utan Kontakt', 'personnummer' => $otherTwelve, 'address' => 'Utangatan 1'],
        ],
    ],
    '93018' => [
        'reminder_id' => 93018,
        'contact_id' => 'kt_saknad',
        'owners' => [
            ['name' => 'Saknad Person', 'personnummer' => $twelve, 'address' => 'Saknadgatan 1'],
        ],
    ],
];
list_ok(jeab_purchase_json_write($orders) === true, 'ordrarna ligger i JSON');
if (function_exists('jeab_purchase_cache_forget')) {
    jeab_purchase_cache_forget();
}
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);

$store = jeab_store();
list_ok($store instanceof JeabStore, 'lagret finns');
$boBefore = '';
$ada = jeab_kontakt_get('kt_amb_a');
list_ok(is_array($ada), 'Ada kan läsas');
foreach (is_array($ada['owners'] ?? null) ? $ada['owners'] : [] as $owner) {
    if (is_array($owner) && (string) ($owner['name'] ?? '') === 'Bo Ambig') {
        $boBefore = (string) ($owner['personnummer'] ?? '');
    }
}
list_ok($boBefore !== '', 'Bo har ett lagrat nummer före läsningen');

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-pnr-list']));
file_put_contents($errLog, '');
$storedNeedles = [];
$listCollect = static function (mixed $value) use (&$listCollect, &$storedNeedles): void {
    if (!is_array($value)) {
        return;
    }
    foreach ($value as $key => $item) {
        if (is_string($item) && in_array((string) $key, ['personnummer', 'pnr', 'SocialSecurityNumber'], true) && trim($item) !== '') {
            $storedNeedles[] = trim($item);
        } elseif (is_array($item)) {
            $listCollect($item);
        }
    }
};
$listCollect(jeab_kontakter_load());
$listCollect(jeab_purchase_all());
$needles = array_values(array_unique(array_merge($needles, $storedNeedles, [$boBefore])));
$before = list_tree($tmp);

$manual = 0;
foreach (jeab_purchase_all() as $row) {
    if (is_array($row) && jeab_contacts_sole_row_pnr_name_ambiguous($row)) {
        $manual++;
    }
}
$verify = jeab_contacts_sole_verify($store);
$list = jeab_contacts_sole_pnr_ambiguous_list(['dry' => 0], $store);
$listJson = json_encode($list, JSON_UNESCAPED_UNICODE);
list_ok(is_string($listJson), 'listan kan kodas');
list_ok((int) ($verify['pnr_name_ambiguous'] ?? -1) === $manual, 'sole_verify räknar samma villkor');
list_ok((int) ($list['count'] ?? -1) === $manual && count((array) ($list['rows'] ?? [])) === $manual, 'listan har samma antal som sole_verify');
list_ok(!empty($list['ok']) && !empty($list['dry']) && empty($list['written']), 'dry=0 skriver inte');
list_ok($manual === 3, 'tre ordrar är tvetydiga');

$rows = [];
foreach ((array) ($list['rows'] ?? []) as $row) {
    if (is_array($row)) {
        $rows[(int) ($row['reminder_id'] ?? 0)] = $row;
    }
}
list_ok(array_keys($rows) === [93011, 93013, 93014], 'bara de tvetydiga ordrarna, i id-ordning');
$adaRow = $rows[93011];
list_ok(array_keys($adaRow) === ['reminder_id', 'order_id', 'contact_id', 'street', 'postal_code', 'city', 'owners', 'pnr_missing', 'address_missing', 'reason', 'fortnox_customer'], 'raden har order, kontakt, adress, ägare, luckor, orsak och Fortnox');
list_ok((int) ($adaRow['order_id'] ?? 0) === 93011 && (string) ($adaRow['contact_id'] ?? '') === 'kt_amb_a', 'Ada-raden pekar på ordern och kontakten');
list_ok((string) ($adaRow['street'] ?? '') === 'Ambiguagatan 1' && (string) ($adaRow['postal_code'] ?? '') === '11122' && (string) ($adaRow['city'] ?? '') === 'Teststad', 'Adas gata, postnummer och ort kommer från kontakten');
list_ok(($adaRow['pnr_missing'] ?? null) === true && ($adaRow['address_missing'] ?? null) === true, 'Ada saknar nummer och adress på någon ägare');
$adaOwners = is_array($adaRow['owners'] ?? null) ? $adaRow['owners'] : [];
list_ok($adaOwners === [
    ['index' => 0, 'name' => 'Ada Ambig', 'pnr_missing' => true, 'address_missing' => true, 'reason' => 'Personnumret saknas.'],
    ['index' => 1, 'name' => 'Bo Ambig', 'pnr_missing' => false, 'address_missing' => true, 'reason' => 'Personnumret har fel format.'],
    ['index' => 2, 'name' => '', 'pnr_missing' => true, 'address_missing' => true, 'reason' => 'Personnumret saknas.'],
], 'ägarna har index, luckor och orsak utan nummer');
list_ok(($adaRow['reason'] ?? null) === [
    ['index' => 0, 'reason' => 'Personnumret saknas.'],
    ['index' => 1, 'reason' => 'Personnumret har fel format.'],
    ['index' => 2, 'reason' => 'Personnumret saknas.'],
], 'Adas order säger saknat och ogiltigt nummer per plats');
list_ok(($adaRow['fortnox_customer'] ?? null) === ['order' => ['42'], 'contact' => ['88', 'K77']], 'Fortnox-kundnummer från order och kontakt, inte numret som låg i kundfältet');

$danRow = $rows[93013];
list_ok((string) ($danRow['contact_id'] ?? '') === 'kt_amb_c' && (string) ($danRow['street'] ?? '') === 'Dangatan 9' && (string) ($danRow['postal_code'] ?? '') === '22233' && (string) ($danRow['city'] ?? '') === 'Testort', 'Dans adress kommer från kontakten');
list_ok(($danRow['pnr_missing'] ?? null) === true && ($danRow['address_missing'] ?? null) === false, 'Dan saknar nummer och har adress');
list_ok(($danRow['owners'] ?? null) === [
    ['index' => 0, 'name' => 'Dan Dubbel', 'pnr_missing' => true, 'address_missing' => false, 'reason' => 'Personnumret saknas.'],
], 'Dans adressfält adress räknas som ifyllt');
list_ok(($danRow['reason'] ?? null) === [
    ['index' => 0, 'reason' => 'Personnumret saknas.'],
], 'Dan saknar nummer');
list_ok(($danRow['fortnox_customer'] ?? null) === ['order' => ['K9'], 'contact' => []], 'Dan har kundnummer bara på ordern');

$evaRow = $rows[93014];
list_ok((string) ($evaRow['street'] ?? '') === 'Evagatan 3' && (string) ($evaRow['postal_code'] ?? '') === '33344' && (string) ($evaRow['city'] ?? '') === 'Testby', 'Evas adress kommer från kontakten');
list_ok(($evaRow['pnr_missing'] ?? null) === false && ($evaRow['address_missing'] ?? null) === true, 'Evas lagrade mask är inte en tom lucka');
list_ok(($evaRow['owners'] ?? null) === [
    ['index' => 0, 'name' => 'Eva Mask', 'pnr_missing' => false, 'address_missing' => true, 'reason' => 'Personnumret har fel format.'],
], 'Evas ägare har index utan mask');
list_ok(($evaRow['reason'] ?? null) === [
    ['index' => 0, 'reason' => 'Personnumret har fel format.'],
], 'Evas mask är ogiltigt format, inte en tom lucka');
list_ok(($evaRow['fortnox_customer'] ?? null) === ['order' => [], 'contact' => []], 'ett kundnummer som är ett personnummer lämnas ute');

$keys = [];
list_keys($list, $keys);
list_ok(!in_array('personnummer', $keys, true) && !in_array('pnr', $keys, true) && !in_array('SocialSecurityNumber', $keys, true) && !in_array('OrganisationNumber', $keys, true), 'svaret har inga nummerfält');
list_ok(!list_pnr_pattern($listJson), 'svaret har inget 10- eller 12-siffrigt personnummermönster');
$listLeak = false;
foreach ($needles as $needle) {
    if (str_contains($listJson, $needle)) {
        $listLeak = true;
    }
}
list_ok(!$listLeak, 'svaret saknar lagrat, maskat och ofullständigt nummer');

$boAfter = '';
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$adaAfter = jeab_kontakt_get('kt_amb_a');
foreach (is_array($adaAfter['owners'] ?? null) ? $adaAfter['owners'] : [] as $owner) {
    if (is_array($owner) && (string) ($owner['name'] ?? '') === 'Bo Ambig') {
        $boAfter = (string) ($owner['personnummer'] ?? '');
    }
}
list_ok($boAfter === $boBefore, 'läsningen lämnar det lagrade numret');
list_ok(list_tree($tmp) === $before, 'listan rör inga filer');
$errText = is_file($errLog) ? (string) file_get_contents($errLog) : '';
$errClean = true;
foreach ($needles as $needle) {
    if (str_contains($errText, $needle)) {
        $errClean = false;
    }
}
list_ok($errClean && !list_pnr_pattern($errText), 'felloggen har inget personnummer');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$denied = jeab_contacts_sole_dispatch(['action' => 'kontakter.pnr_ambiguous_list', 'dry' => 0]);
list_ok(($denied['status'] ?? 0) === 401 && ($denied['ok'] ?? true) === false, 'utan token blir det 401');
list_ok(list_tree($tmp) === $before, 'nekad hook skriver inget');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-pnr-list';
$allowed = jeab_contacts_sole_dispatch(['action' => 'kontakter.pnr_ambiguous_list', 'dry' => 0]);
$allowedJson = json_encode($allowed, JSON_UNESCAPED_UNICODE);
list_ok(($allowed['status'] ?? 0) === 200 && (int) ($allowed['count'] ?? -1) === $manual && empty($allowed['written']) && !empty($allowed['dry']), 'dispatch med token läser och skriver inte');
$dispatchLeak = !is_string($allowedJson) || list_pnr_pattern($allowedJson);
if (is_string($allowedJson)) {
    foreach ($needles as $needle) {
        if (str_contains($allowedJson, $needle)) {
            $dispatchLeak = true;
        }
    }
}
list_ok(!$dispatchLeak, 'dispatch-svaret har inget personnummer');
list_ok(list_tree($tmp) === $before, 'dispatch skriver inget');

$runner = $tmp . '/hook-runner.php';
file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$GLOBALS['_JEAB_DATA_DIR'] = $argv[1];
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $argv[1];
putenv('JEAB_DISABLE_SQLITE=0');
$_ENV['JEAB_DISABLE_SQLITE'] = '0';
putenv('CONTACTS_SOLE');
ini_set('log_errors', '1');
ini_set('error_log', $argv[1] . '/hook-error.log');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $argv[2];
$_SERVER['CONTENT_TYPE'] = 'application/json';
$GLOBALS['_JEAB_RAW_BODY'] = $argv[3];
require $argv[4];
PHP);
$contactsFile = jeab_kontakter_path();
$purchaseFile = jeab_purchase_path();
$hashFile = static function (string $path): string {
    return hash('sha256', is_file($path) ? (string) file_get_contents($path) : '');
};
$contactsHash = $hashFile($contactsFile);
$purchaseHash = $hashFile($purchaseFile);
$runHook = static function (string $token, string $body) use ($tmp, $runner, $root): array {
    $out = [];
    $code = 0;
    exec(
        'php ' . escapeshellarg($runner) . ' '
        . escapeshellarg($tmp) . ' '
        . escapeshellarg($token) . ' '
        . escapeshellarg($body) . ' '
        . escapeshellarg($root . '/hook.php'),
        $out,
        $code
    );

    return ['code' => $code, 'json' => json_decode(implode("\n", $out), true), 'raw' => implode("\n", $out)];
};
$hookDeny = $runHook('', json_encode(['action' => 'kontakter.pnr_ambiguous_list', 'dry' => 0]));
list_ok($hookDeny['code'] === 0 && is_array($hookDeny['json']) && ($hookDeny['json']['ok'] ?? true) === false, 'hook.php utan token nekar');
list_ok(!list_pnr_pattern($hookDeny['raw']), 'nekat hook-svar har inget personnummermönster');
$hookList = $runHook('tok-pnr-list', json_encode(['action' => 'kontakter.pnr_ambiguous_list', 'dry' => 0]));
list_ok($hookList['code'] === 0 && is_array($hookList['json']) && (int) ($hookList['json']['count'] ?? -1) === $manual && empty($hookList['json']['written']), 'hook.php lämnar samma antal');
$hookLeak = list_pnr_pattern($hookList['raw']) || list_pnr_pattern($hookDeny['raw']);
foreach ($needles as $needle) {
    if (str_contains($hookList['raw'], $needle) || str_contains($hookDeny['raw'], $needle)) {
        $hookLeak = true;
    }
}
list_ok(!$hookLeak, 'hook.php-svaret har inget 10- eller 12-siffrigt personnummer');
$hookErr = is_file($tmp . '/hook-error.log') ? (string) file_get_contents($tmp . '/hook-error.log') : '';
$hookErrClean = !list_pnr_pattern($hookErr);
foreach ($needles as $needle) {
    if (str_contains($hookErr, $needle)) {
        $hookErrClean = false;
    }
}
list_ok($hookErrClean, 'hookens fellogg har inget personnummer');
list_ok($hashFile($contactsFile) === $contactsHash && $hashFile($purchaseFile) === $purchaseHash, 'hook.php lämnar kontakter och ordrar orörda');

echo "contacts sole pnr ambiguous list: ok\n";
