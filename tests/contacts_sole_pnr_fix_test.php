<?php

declare(strict_types=1);

/**
 * kontakter.pnr_fix skriver bara när ordern är tvetydig, kontakten stämmer
 * och kundnumret redan ligger på ordern eller kontakten.
 * Svar och logg får inte innehålla personnumret. Ångerloggen har den tomma före-bilden.
 */

$tmp = sys_get_temp_dir() . '/jeab-pnr-fix-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$errLog = $tmp . '/php-error.log';
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
putenv('JEAB_DISABLE_SQLITE=0');
$_ENV['JEAB_DISABLE_SQLITE'] = '0';
putenv('CONTACTS_SOLE=1');
$_ENV['CONTACTS_SOLE'] = '1';
putenv('JEAB_PNR_HMAC_KEY=pnr-fix-test-key');
$_ENV['JEAB_PNR_HMAC_KEY'] = 'pnr-fix-test-key';
ini_set('log_errors', '1');
ini_set('error_log', $errLog);
ini_set('display_errors', '0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox_pnr.php';

function fix_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function fix_pattern(string $text): bool
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

/** @param list<string> $needles */
function fix_leak(string $text, array $needles): bool
{
    if (fix_pattern($text)) {
        return true;
    }
    foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($text, $needle)) {
            return true;
        }
    }

    return false;
}

function fix_luhn_digit(string $nine): string
{
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $n = (int) $nine[$i];
        if ($i % 2 === 0) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
    }

    return (string) ((10 - ($sum % 10)) % 10);
}

/** @param list<array<string, mixed>> $owners */
function fix_slot_pnr(array $owners, int $index): string
{
    $owner = $owners[$index] ?? null;

    return is_array($owner) ? trim((string) ($owner['personnummer'] ?? '')) : '';
}

/** @return list<array<string, mixed>> */
function fix_owners(string $id): array
{
    $row = jeab_kontakt_get($id);
    $out = [];
    foreach (is_array($row) && is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $out[] = $owner;
        }
    }

    return $out;
}

$nine = '900101123';
$ten = $nine . fix_luhn_digit($nine);
$good = '19' . $ten;
$good8 = substr($good, 0, 8) . '-' . substr($good, 8);
$good6 = substr($ten, 0, 6) . '-' . substr($ten, 6);
$bad = '199013410001';
$shaped = '199013410099';
fix_ok(jeab_norm_pnr($good) === $good, 'syntetiskt nummer klarar datum och kontrollsiffra');
fix_ok(jeab_norm_pnr($bad) === '' && jeab_norm_pnr($shaped) === '', 'ogiltiga nummer stoppas');
$needles = [$good, $good8, $good6, $ten, $bad, $shaped, substr($good, 2, 6) . '-****'];
$hmac = jeab_contacts_sole_pnr_hmac($good);
fix_ok($hmac !== '' && !fix_pattern($hmac), 'HMAC finns och är inte ett siffermönster');
$needles[] = $hmac;

$root = dirname(__DIR__);
$hookSrc = (string) file_get_contents($root . '/hook.php');
$soleSrc = (string) file_get_contents($root . '/src/contacts_sole.php');
$hookFrom = strpos($hookSrc, "['kontakter.sole'");
$hookTo = strpos($hookSrc, 'jeab_contacts_sole_dispatch');
$hookGate = ($hookFrom !== false && $hookTo !== false && $hookTo > $hookFrom) ? substr($hookSrc, $hookFrom, $hookTo - $hookFrom) : '';
fix_ok(str_contains($hookGate, "'kontakter.pnr_fix'") && str_contains($hookGate, "'kontakter.pnr_ambiguous_list'"), 'pnr_fix ligger i samma grind som listan');
$dispatchFrom = strpos($soleSrc, 'function jeab_contacts_sole_dispatch(');
$dispatchBody = $dispatchFrom !== false ? substr($soleSrc, $dispatchFrom, 2500) : '';
fix_ok(str_contains($dispatchBody, 'jeab_inkorg_hook_authorized') && strpos($dispatchBody, 'jeab_inkorg_hook_authorized') < strpos($dispatchBody, "'kontakter.pnr_fix'"), 'tokengrinden ligger före pnr_fix');
$fixFrom = strpos($soleSrc, 'function jeab_contacts_sole_pnr_fix(');
$fixTo = strpos($soleSrc, 'function jeab_contacts_sole_row_pnr_name_ambiguous(');
$fixBody = ($fixFrom !== false && $fixTo !== false && $fixTo > $fixFrom) ? substr($soleSrc, $fixFrom, $fixTo - $fixFrom) : '';
fix_ok($fixBody !== '' && !str_contains($fixBody, 'error_log') && !str_contains($fixBody, 'jeab_mask_pnr') && !preg_match('/\b(echo|print|printf|var_dump|var_export)\b/', $fixBody), 'pnr_fix skriver inte ut och loggar inte numret');
fix_ok(str_contains($fixBody, 'jeab_kontakt_save_input') && !str_contains($fixBody, 'jeab_fastighet_write_contact'), 'skrivningen går genom ägarsparningen');

$now = '2026-01-01 00:00:00';
$contact = static function (string $id, string $street, array $owners, array $extra = []) use ($now): array {
    return $extra + [
        'id' => $id,
        'street' => $street,
        'street_key' => '',
        'postal_code' => '11122',
        'city' => 'Teststad',
        'owners' => $owners,
        'kind' => 'person',
        'ovrigt' => '',
        'created_at' => $now,
        'updated_at' => $now,
    ];
};
jeab_kontakter_mutate(static function (array &$contacts) use ($contact): void {
    $contacts['kt_fixa'] = $contact('kt_fixa', 'Adagatan 1', [
        ['name' => 'Ada Fix', 'personnummer' => '', 'address' => ''],
        ['name' => 'Bo Fix', 'personnummer' => '', 'address' => ''],
    ]);
    $contacts['kt_fixb'] = $contact('kt_fixb', 'Ciagatan 2', [
        ['name' => 'Cia Klar', 'personnummer' => '', 'address' => ''],
    ]);
    $contacts['kt_fixc'] = $contact('kt_fixc', 'Dangatan 3', [
        ['name' => 'Dan Tom', 'personnummer' => '', 'address' => ''],
    ]);
    $contacts['kt_fixd'] = $contact('kt_fixd', 'Evagatan 4', [
        ['name' => 'Eva Ens', 'personnummer' => '', 'address' => ''],
    ]);
    $contacts['kt_fixe'] = $contact('kt_fixe', 'Kundgatan 5', [
        ['name' => 'Eva Kund', 'personnummer' => '', 'address' => ''],
    ], ['fortnox_kundnummer' => 'K88']);
    $contacts['kt_fixh'] = $contact('kt_fixh', 'Hookgatan 6', [
        ['name' => 'Hugo Hook', 'personnummer' => '', 'address' => ''],
        ['name' => 'Ida Hook', 'personnummer' => '', 'address' => ''],
    ]);
    $contacts['kt_fixs'] = $contact('kt_fixs', 'Slotgatan 7', [
        ['name' => 'Mia Slot', 'personnummer' => '', 'address' => ''],
        ['name' => 'Noa Slot', 'personnummer' => '', 'address' => ''],
    ]);
});
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);

$orders = [
    '94011' => [
        'reminder_id' => 94011,
        'contact_id' => 'kt_fixa',
        'company' => 'jarfalla',
        'fortnox_customer' => '42',
        'fortnox_customer_number' => $shaped,
        'owners' => [
            ['name' => 'Någon Annan', 'personnummer' => '', 'address' => 'Annan gata 1'],
        ],
    ],
    '94012' => [
        'reminder_id' => 94012,
        'contact_id' => 'kt_fixb',
        'company' => 'jarfalla',
        'fortnox_customer' => 'K9',
        'owners' => [
            ['name' => 'Cia Klar', 'personnummer' => '', 'address' => 'Ciagatan 2'],
        ],
    ],
    '94013' => [
        'reminder_id' => 94013,
        'contact_id' => 'kt_fixc',
        'company' => 'jarfalla',
        'owners' => [
            ['name' => 'Annan Dan', 'personnummer' => '', 'address' => 'Dangatan 1'],
        ],
    ],
    '94014' => [
        'reminder_id' => 94014,
        'contact_id' => 'kt_fixd',
        'company' => 'jarfalla',
        'fortnox_customer' => '77',
        'owners' => [
            ['name' => 'Annan Eva', 'personnummer' => '', 'address' => 'Evagatan 1'],
        ],
    ],
    '94015' => [
        'reminder_id' => 94015,
        'contact_id' => 'kt_fixe',
        'company' => 'jarfalla',
        'owners' => [
            ['name' => 'Annan Kund', 'personnummer' => '', 'address' => 'Kundgatan 1'],
        ],
    ],
    '94021' => [
        'reminder_id' => 94021,
        'contact_id' => 'kt_fixh',
        'company' => 'jarfalla',
        'fortnox_customer' => '42',
        'owners' => [
            ['name' => 'Annan Hook', 'personnummer' => '', 'address' => 'Hookgatan 1'],
        ],
    ],
    '94022' => [
        'reminder_id' => 94022,
        'contact_id' => 'kt_fixs',
        'company' => 'jarfalla',
        'fortnox_customer' => '42',
        'owners' => [
            ['name' => 'Annan Slot', 'personnummer' => '', 'address' => 'Slotgatan 1'],
        ],
    ],
];
fix_ok(jeab_purchase_json_write($orders) === true, 'ordrarna ligger i JSON');
if (function_exists('jeab_purchase_cache_forget')) {
    jeab_purchase_cache_forget();
}
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);

$store = jeab_store();
fix_ok($store instanceof JeabPdoStore, 'sqlite-lagret finns');
fix_ok(jeab_contacts_sole(), 'lagringsflaggan är på');
fix_ok(jeab_contacts_sole_row_pnr_name_ambiguous($orders['94011']) === true, '94011 är tvetydig');
fix_ok(jeab_contacts_sole_row_pnr_name_ambiguous($orders['94012']) === false, '94012 är entydig');
fix_ok(jeab_contacts_sole_row_pnr_name_ambiguous($orders['94013']) === true, '94013 är tvetydig utan kundnummer');

$fx = new stdClass();
$fx->calls = 0;
$fx->mode = 'ok';
$fx->account = '';
$fx->number = '';
$GLOBALS['_JEAB_FORTNOX_PNR_FACTORY'] = static function (string $account) use ($fx, $good, $bad): FortnoxAPI {
    return new class($account, $fx, $good, $bad) extends FortnoxAPI {
        public function __construct(private string $accountName, private stdClass $fx, private string $good, private string $bad)
        {
        }

        public function getCustomer(string $number): array
        {
            $this->fx->calls++;
            $this->fx->account = $this->accountName;
            $this->fx->number = $number;
            if ($this->fx->mode === 'throw') {
                throw new RuntimeException($this->good);
            }
            if ($this->fx->mode === 'empty') {
                return ['CustomerNumber' => $number, 'OrganisationNumber' => ''];
            }
            if ($this->fx->mode === 'bad') {
                return ['CustomerNumber' => $number, 'OrganisationNumber' => $this->bad];
            }
            if ($this->fx->mode === 'ssn') {
                return ['CustomerNumber' => $number, 'OrganisationNumber' => '', 'SocialSecurityNumber' => $this->good];
            }

            return ['CustomerNumber' => $number, 'OrganisationNumber' => $this->good];
        }
    };
};

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-pnr-fix']));
file_put_contents($errLog, '');

$seen = '';
$take = static function (array $result) use (&$seen, $needles): array {
    // batch_id är 16 slumpade hex-tecken (jeab_contacts_sole_batch). Ibland blir 10+ av dem
    // siffror i rad och fix_pattern slår till fast det inte är ett personnummer.
    $check = $result;
    array_walk_recursive($check, static function (mixed &$v, mixed $k): void {
        if ($k === 'batch_id' && is_string($v) && preg_match('/^[a-f0-9]{16}$/', $v) === 1) {
            $v = 'batch';
        }
    });
    $json = json_encode($check, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    fix_ok(is_string($json) && !fix_leak($json, $needles), 'svaret saknar personnummer');
    $seen .= $json . "\n";

    return $result;
};

$calls = 0;
$guard = $take(jeab_contacts_sole_pnr_fix([
    'order_id' => 94012,
    'contact_id' => 'kt_fixb',
    'fortnox_kundnr' => 'K9',
    'slot' => 1,
    'dry' => 1,
], $store));
fix_ok($guard === ['ok' => false, 'error' => 'Ordern är inte tvetydig.', 'dry' => true, 'would_write' => false], 'entydig order stoppas');
fix_ok($fx->calls === $calls, 'entydig order läser inte Fortnox');

$guard = $take(jeab_contacts_sole_pnr_fix([
    'order_id' => 94011,
    'contact_id' => 'kt_fixb',
    'fortnox_kundnr' => '42',
    'slot' => 1,
    'dry' => 1,
], $store));
fix_ok($guard === ['ok' => false, 'error' => 'Kontakten hör inte till ordern.', 'dry' => true, 'would_write' => false], 'fel kontakt stoppas');
fix_ok($fx->calls === $calls, 'fel kontakt läser inte Fortnox');

$guard = $take(jeab_contacts_sole_pnr_fix([
    'order_id' => 94011,
    'contact_id' => 'kt_fixa',
    'fortnox_kundnr' => '99',
    'slot' => 1,
    'dry' => 1,
], $store));
fix_ok($guard === ['ok' => false, 'error' => 'Fortnox-kundnumret stämmer inte.', 'dry' => true, 'would_write' => false], 'fel kundnummer stoppas');
fix_ok($fx->calls === $calls, 'fel kundnummer läser inte Fortnox');

$guard = $take(jeab_contacts_sole_pnr_fix([
    'order_id' => 94013,
    'contact_id' => 'kt_fixc',
    'fortnox_kundnr' => '42',
    'slot' => 1,
    'dry' => 1,
], $store));
fix_ok($guard === ['ok' => false, 'error' => 'Fortnox-kundnummer saknas.', 'dry' => true, 'would_write' => false], 'saknat kundnummer stoppas');
fix_ok($fx->calls === $calls, 'saknat kundnummer läser inte Fortnox');

$guard = $take(jeab_contacts_sole_pnr_fix([
    'order_id' => 94011,
    'contact_id' => 'kt_fixa',
    'fortnox_kundnr' => '42',
    'slot' => 3,
    'dry' => 1,
], $store));
fix_ok($guard === ['ok' => false, 'error' => 'Ägarplatsen ska vara 1 eller 2.', 'dry' => true, 'would_write' => false], 'plats 3 stoppas');

$guard = $take(jeab_contacts_sole_pnr_fix([
    'order_id' => 94014,
    'contact_id' => 'kt_fixd',
    'fortnox_kundnr' => '77',
    'slot' => 2,
    'dry' => 1,
], $store));
fix_ok($guard === ['ok' => false, 'error' => 'Ägarplatsen finns inte.', 'dry' => true, 'would_write' => false], 'saknad ägarplats stoppas');
fix_ok($fx->calls === $calls, 'saknad ägarplats läser inte Fortnox');
fix_ok(fix_slot_pnr(fix_owners('kt_fixa'), 0) === '' && fix_slot_pnr(fix_owners('kt_fixa'), 1) === '', 'spärrarna lämnar kontakten tom');

$fx->mode = 'bad';
$badRes = $take(jeab_contacts_sole_pnr_fix([
    'order_id' => 94011,
    'contact_id' => 'kt_fixa',
    'fortnox_kundnr' => '42',
    'slot' => 1,
    'dry' => 1,
], $store));
fix_ok($badRes === ['ok' => false, 'error' => 'Personnumret har fel format.', 'dry' => true, 'would_write' => false], 'fel kontrollsiffra stoppas');
fix_ok($fx->calls === 1 && $fx->account === 'Jarfalla' && $fx->number === '42', 'ogiltigt nummer läses från rätt kund');
fix_ok(fix_slot_pnr(fix_owners('kt_fixa'), 0) === '', 'ogiltigt nummer skrivs inte');

$fx->mode = 'throw';
$thrown = $take(jeab_contacts_sole_pnr_fix([
    'order_id' => 94011,
    'contact_id' => 'kt_fixa',
    'fortnox_kundnr' => '42',
    'slot' => '1',
    'dry' => 1,
], $store));
fix_ok($thrown === ['ok' => false, 'error' => 'Fortnox-kunden kunde inte läsas.', 'dry' => true, 'would_write' => false], 'läsfel lämnar inte numret');

$fx->mode = 'empty';
$emptyFx = $take(jeab_contacts_sole_pnr_fix([
    'order_id' => 94011,
    'contact_id' => 'kt_fixa',
    'fortnox_kundnr' => '42',
    'slot' => 1,
    'dry' => 1,
], $store));
fix_ok($emptyFx['ok'] === false && ($emptyFx['would_write'] ?? true) === false, 'tomt kundkort skrivs inte');

$fx->mode = 'ok';
$dry = $take(jeab_contacts_sole_pnr_fix([
    'order_id' => 94011,
    'contact_id' => 'kt_fixa',
    'fortnox_kundnr' => '42',
    'slot' => 1,
    'dry' => 1,
], $store));
fix_ok($dry === ['ok' => true, 'dry' => true, 'would_write' => true], 'dry=1 validerar och skriver inte');
fix_ok(fix_slot_pnr(fix_owners('kt_fixa'), 0) === '' && fix_slot_pnr(fix_owners('kt_fixa'), 1) === '', 'dry=1 lämnar båda platserna tomma');

$implicit = $take(jeab_contacts_sole_pnr_fix([
    'order_id' => 94011,
    'contact_id' => 'kt_fixa',
    'fortnox_kundnr' => '42',
    'slot' => 1,
], $store));
fix_ok($implicit === ['ok' => true, 'dry' => true, 'would_write' => true], 'utelämnad dry skriver inte');
fix_ok(fix_slot_pnr(fix_owners('kt_fixa'), 0) === '', 'utelämnad dry lämnar platsen tom');

$fx->mode = 'ssn';
$ssn = $take(jeab_contacts_sole_pnr_fix([
    'order_id' => 94015,
    'contact_id' => 'kt_fixe',
    'fortnox_kundnr' => 'K88',
    'slot' => 1,
    'dry' => 1,
], $store));
fix_ok($ssn === ['ok' => true, 'dry' => true, 'would_write' => true], 'kundnummer på kontakten och nummer i personfältet godkänns torrt');
fix_ok($fx->number === 'K88' && fix_slot_pnr(fix_owners('kt_fixe'), 0) === '', 'kontaktens kundnummer skrivs inte i torrkörningen');
$fx->mode = 'ok';

$beforeUndo = count(jeab_contacts_sole_undo_all());
$wet = $take(jeab_contacts_sole_pnr_fix([
    'order_id' => 94011,
    'contact_id' => 'kt_fixa',
    'fortnox_kundnr' => '42',
    'slot' => 1,
    'dry' => 0,
], $store));
fix_ok($wet === ['ok' => true], 'skrivningen svarar bara ok');
$adaOwners = fix_owners('kt_fixa');
$written = fix_slot_pnr($adaOwners, 0);
fix_ok(strlen($written) === 12 && !str_contains($written, '-') && hash_equals($good, $written), 'plats 1 får det kontrollerade numret');
fix_ok(fix_slot_pnr($adaOwners, 1) === '' && (string) ($adaOwners[0]['name'] ?? '') === 'Ada Fix' && (string) ($adaOwners[1]['name'] ?? '') === 'Bo Fix', 'bara den valda platsens nummer ändras');

$again = $take(jeab_contacts_sole_pnr_fix([
    'order_id' => 94011,
    'contact_id' => 'kt_fixa',
    'fortnox_kundnr' => '42',
    'slot' => 1,
    'dry' => 0,
], $store));
fix_ok($again === ['ok' => false, 'error' => 'Ägarplatsen har redan ett nummer.'], 'fylld plats skrivs inte om');

$rows = jeab_contacts_sole_undo_all();
fix_ok(count($rows) === $beforeUndo + 1, 'en ångerpost skrevs');
$undo = $rows[count($rows) - 1];
$beforeJson = (string) ($undo['before_json'] ?? '');
$afterJson = (string) ($undo['after_json'] ?? '');
fix_ok((string) ($undo['kind'] ?? '') === 'owner' && (string) ($undo['entity'] ?? '') === 'contact' && (string) ($undo['entity_id'] ?? '') === 'kt_fixa' && (int) ($undo['undone'] ?? 1) === 0, 'ångerposten pekar på kontakten och kan ångras');
fix_ok(!fix_leak($beforeJson . $afterJson, $needles), 'ångerloggen saknar personnummer');
$before = json_decode($beforeJson, true);
$after = json_decode($afterJson, true);
fix_ok(is_array($before) && is_array($after), 'ångerloggen kan läsas');
$audit = is_array($before['audit'] ?? null) ? $before['audit'] : [];
fix_ok(($audit['who'] ?? '') === 'agent', 'auditen säger agent');
fix_ok(trim((string) ($audit['time'] ?? '')) !== '' && trim((string) ($undo['created_at'] ?? '')) !== '', 'auditen har tid');
fix_ok((int) ($audit['order_id'] ?? 0) === 94011 && (string) ($audit['contact_id'] ?? '') === 'kt_fixa' && (int) ($audit['slot'] ?? 0) === 1 && (string) ($audit['result'] ?? '') === 'wrote', 'auditen har order, kontakt, plats och resultat');
fix_ok(($after['audit'] ?? null) === $audit, 'samma audit ligger i efter-bilden');
fix_ok(!str_contains($beforeJson, 'pnr_hmac') && !str_contains($afterJson, 'pnr_hmac'), 'tom före-bild ger ingen HMAC');
$preview = $take(jeab_contacts_sole_undo([
    'batch_id' => (string) ($undo['batch_id'] ?? ''),
    'dry' => 1,
], $store));
fix_ok(!empty($preview['ok']) && !empty($preview['dry']) && empty($preview['written']) && (int) ($preview['owners'] ?? 0) >= 1, 'ångringen kan förhandsvisas');
$restoredOwners = jeab_contacts_sole_undo_owner_fields(
    array_values(array_filter(is_array($before['owners'] ?? null) ? $before['owners'] : [], 'is_array')),
    $adaOwners
);
fix_ok(jeab_fastighet_write_contact('kt_fixa', ['owners' => $restoredOwners]) === true, 'före-bilden kan skrivas tillbaka');
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$restored = fix_owners('kt_fixa');
fix_ok(fix_slot_pnr($restored, 0) === '' && fix_slot_pnr($restored, 1) === '', 'ångringen återställer den tomma platsen');

$slot2 = $take(jeab_contacts_sole_pnr_fix([
    'order_id' => 94022,
    'contact_id' => 'kt_fixs',
    'fortnox_kundnr' => '42',
    'slot' => 2,
    'dry' => 0,
], $store));
fix_ok($slot2 === ['ok' => true], 'plats 2 skrivs');
$slotOwners = fix_owners('kt_fixs');
fix_ok(fix_slot_pnr($slotOwners, 0) === '' && hash_equals($good, fix_slot_pnr($slotOwners, 1)), 'bara plats 2 får numret');
$slotUndo = jeab_contacts_sole_undo_all();
$slotRow = $slotUndo[count($slotUndo) - 1];
fix_ok((int) (json_decode((string) ($slotRow['before_json'] ?? ''), true)['audit']['slot'] ?? 0) === 2, 'auditen säger plats 2');
fix_ok(!fix_leak((string) ($slotRow['before_json'] ?? '') . (string) ($slotRow['after_json'] ?? ''), $needles), 'plats 2-loggen saknar personnummer');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$denied = $take(jeab_contacts_sole_dispatch(['action' => 'kontakter.pnr_fix', 'dry' => 0]));
fix_ok(($denied['ok'] ?? true) === false && ($denied['status'] ?? 0) === 401, 'utan token blir det 401');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-pnr-fix';
$allowed = $take(jeab_contacts_sole_dispatch([
    'action' => 'kontakter.pnr_fix',
    'order_id' => 94012,
    'contact_id' => 'kt_fixb',
    'fortnox_kundnr' => 'K9',
    'slot' => 1,
    'dry' => 1,
]));
unset($allowed['status']);
fix_ok($allowed === ['ok' => false, 'error' => 'Ordern är inte tvetydig.', 'dry' => true, 'would_write' => false], 'dispatch stoppar entydig order');

$errText = is_file($errLog) ? (string) file_get_contents($errLog) : '';
fix_ok(!fix_leak($errText, $needles), 'felloggen saknar personnummer');
fix_ok(!fix_leak($seen, $needles), 'samlade svar saknar personnummer');

$pnrFile = $tmp . '/pnr-secret.txt';
$callsFile = $tmp . '/fortnox-calls.txt';
file_put_contents($pnrFile, $good);
file_put_contents($callsFile, '0');
$prepend = $tmp . '/fortnox-prepend.php';
$fortnoxPath = var_export($root . '/src/fortnox.php', true);
file_put_contents($prepend, str_replace('__FORTNOX__', $fortnoxPath, <<<'PHP'
<?php
$GLOBALS['_JEAB_FORTNOX_PNR_FACTORY'] = static function (string $account): FortnoxAPI {
    require_once __FORTNOX__;
    $org = trim((string) file_get_contents((string) getenv('JEAB_TEST_PNR_FILE')));
    $calls = (string) getenv('JEAB_TEST_CALLS_FILE');
    file_put_contents($calls, (string) (((int) (is_file($calls) ? file_get_contents($calls) : '0')) + 1));
    return new class($account, $org) extends FortnoxAPI {
        public function __construct(private string $accountName, private string $org)
        {
        }

        public function getCustomer(string $number): array
        {
            if ($this->accountName !== 'Jarfalla') {
                throw new RuntimeException('fel konto');
            }

            return ['CustomerNumber' => $number, 'OrganisationNumber' => $this->org];
        }
    };
};
PHP));
$runner = $tmp . '/hook-runner.php';
file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$GLOBALS['_JEAB_DATA_DIR'] = $argv[1];
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $argv[1];
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
putenv('JEAB_DISABLE_SQLITE=0');
$_ENV['JEAB_DISABLE_SQLITE'] = '0';
putenv('CONTACTS_SOLE=1');
$_ENV['CONTACTS_SOLE'] = '1';
putenv('JEAB_PNR_HMAC_KEY=pnr-fix-test-key');
$_ENV['JEAB_PNR_HMAC_KEY'] = 'pnr-fix-test-key';
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', $argv[1] . '/hook-error.log');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $argv[2];
$_SERVER['CONTENT_TYPE'] = 'application/json';
$GLOBALS['_JEAB_RAW_BODY'] = $argv[3];
require $argv[4];
PHP);
$runHook = static function (string $token, string $body) use ($tmp, $runner, $root, $prepend, $pnrFile, $callsFile): array {
    $out = [];
    $code = 0;
    $cmd = 'JEAB_TEST_PNR_FILE=' . escapeshellarg($pnrFile)
        . ' JEAB_TEST_CALLS_FILE=' . escapeshellarg($callsFile)
        . ' php -d auto_prepend_file=' . escapeshellarg($prepend)
        . ' ' . escapeshellarg($runner)
        . ' ' . escapeshellarg($tmp)
        . ' ' . escapeshellarg($token)
        . ' ' . escapeshellarg($body)
        . ' ' . escapeshellarg($root . '/hook.php');
    exec($cmd, $out, $code);

    return ['code' => $code, 'raw' => implode("\n", $out)];
};
$hookDeny = $runHook('', json_encode(['action' => 'kontakter.pnr_fix', 'order_id' => 94021, 'contact_id' => 'kt_fixh', 'fortnox_kundnr' => '42', 'slot' => 1, 'dry' => 0], JSON_UNESCAPED_UNICODE));
fix_ok($hookDeny['code'] === 0 && !fix_leak($hookDeny['raw'], $needles), 'hook.php utan token läcker inte');
$denyJson = json_decode($hookDeny['raw'], true);
fix_ok(is_array($denyJson) && ($denyJson['ok'] ?? true) === false && !array_key_exists('status', $denyJson), 'hook.php utan token nekar utan statusfält');
fix_ok((int) file_get_contents($callsFile) === 0, 'nekad hook läser inte Fortnox');

$hookGuard = $runHook('tok-pnr-fix', json_encode(['action' => 'kontakter.pnr_fix', 'order_id' => 94012, 'contact_id' => 'kt_fixb', 'fortnox_kundnr' => 'K9', 'slot' => 1, 'dry' => 1], JSON_UNESCAPED_UNICODE));
$guardJson = json_decode($hookGuard['raw'], true);
fix_ok($hookGuard['code'] === 0 && $guardJson === ['ok' => false, 'error' => 'Ordern är inte tvetydig.', 'dry' => true, 'would_write' => false], 'hook.php stoppar entydig order');
fix_ok(!fix_leak($hookGuard['raw'], $needles) && (int) file_get_contents($callsFile) === 0, 'hookens spärr läcker inte och läser inte Fortnox');

$hookDry = $runHook('tok-pnr-fix', json_encode(['action' => 'kontakter.pnr_fix', 'order_id' => 94021, 'contact_id' => 'kt_fixh', 'fortnox_kundnr' => '42', 'slot' => 1, 'dry' => 1], JSON_UNESCAPED_UNICODE));
$dryJson = json_decode($hookDry['raw'], true);
fix_ok($hookDry['code'] === 0 && $dryJson === ['ok' => true, 'dry' => true, 'would_write' => true], 'hook.php dry=1 rapporterar would_write');
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
fix_ok(fix_slot_pnr(fix_owners('kt_fixh'), 0) === '' && (int) file_get_contents($callsFile) === 1, 'hook.php dry=1 skriver inte');
fix_ok(!fix_leak($hookDry['raw'], $needles), 'hookens torrkörning läcker inte');

$hookWet = $runHook('tok-pnr-fix', json_encode(['action' => 'kontakter.pnr_fix', 'order_id' => 94021, 'contact_id' => 'kt_fixh', 'fortnox_kundnr' => '42', 'slot' => 1, 'dry' => 0], JSON_UNESCAPED_UNICODE));
$wetJson = json_decode($hookWet['raw'], true);
fix_ok($hookWet['code'] === 0 && $wetJson === ['ok' => true], 'hook.php skriver och svarar bara ok');
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$hookOwners = fix_owners('kt_fixh');
fix_ok(hash_equals($good, fix_slot_pnr($hookOwners, 0)) && fix_slot_pnr($hookOwners, 1) === '', 'hook.php skriver bara plats 1');
fix_ok(!fix_leak($hookWet['raw'], $needles) && (int) file_get_contents($callsFile) === 2, 'hookens skrivsvar läcker inte');
$hookErr = is_file($tmp . '/hook-error.log') ? (string) file_get_contents($tmp . '/hook-error.log') : '';
fix_ok(!fix_leak($hookErr, $needles), 'hookens fellogg saknar personnummer');
$hookUndo = jeab_contacts_sole_undo_all();
$hookRow = $hookUndo[count($hookUndo) - 1];
fix_ok((string) ($hookRow['entity_id'] ?? '') === 'kt_fixh' && !fix_leak((string) ($hookRow['before_json'] ?? '') . (string) ($hookRow['after_json'] ?? ''), $needles), 'hookens ångerlogg saknar personnummer');

echo "contacts sole pnr fix: ok\n";
