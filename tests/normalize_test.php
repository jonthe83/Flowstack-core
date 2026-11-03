<?php

declare(strict_types=1);

/**
 * Syntetiska fall för den gemensamma normaliseringen. Ingen kunddata.
 */

$tmp = sys_get_temp_dir() . '/jeab-normalize-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function nz(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function nz_pnr(string $nine): string
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
    $check = (10 - ($sum % 10)) % 10;
    return $nine . $check;
}

$ten = nz_pnr('800101123');
$full = '19' . $ten;
nz(jeab_norm_pnr($full) === $full, '12 siffror utan streck');
nz(jeab_norm_pnr(substr($full, 0, 8) . '-' . substr($full, 8)) === $full, '12 siffror med streck');
nz(jeab_norm_pnr($ten) === $full, '10 siffror får sekel');
nz(jeab_norm_pnr(substr($ten, 0, 6) . '-' . substr($ten, 6)) === $full, '10 siffror med streck');
nz(jeab_norm_pnr('19800101-1234') === '', 'ogiltig Luhn blir tom');
nz(jeab_norm_pnr('19800231-1234') === '', 'ogiltigt datum blir tomt');
nz(jeab_norm_pnr('12345') === '', 'för kort personnummer blir tomt');
$coordTen = nz_pnr('800161123');
nz(jeab_norm_pnr('19' . $coordTen) === '19' . $coordTen, 'samordningsnummer dag 61 godkänns');
$masked = jeab_mask_pnr($full);
nz($masked === '••••••••-' . substr($full, -4), 'masken visar fyra sista');
nz(!str_contains($masked, substr($full, 0, 8)), 'masken innehåller inte hela personnumret');
nz(jeab_mask_pnr('12') === '', 'för kort för mask');
nz(jeab_kontakt_pnr_valid($full) && !jeab_kontakt_pnr_valid('19800101-1234'), 'kontaktens kontroll använder modulen');
nz(jeab_kontakt_pnr_store($ten) === substr($full, 0, 8) . '-' . substr($full, 8), 'sparat personnummer behåller bindestreck');
nz(jeab_pnr_normalize('19800101-1234') === '19800101-1234', 'Fortnox-formen kräver inte Luhn');
nz(jeab_ordrar_normalize_objekt_pnr('8001011234') === '19800101-1234', 'objektformuläret tar sekel utan Luhn');

$mobile = '+46701234567';
foreach ([
    '0701234567',
    '070-123 45 67',
    '070 123 45 67',
    '+46 70 123 45 67',
    '0046701234567',
    '0046 70 123 45 67',
    '46701234567',
    '+460701234567',
] as $variant) {
    nz(jeab_norm_phone($variant) === $mobile, 'telefon ' . $variant);
}
nz(jeab_norm_phone('123') === '', 'för kort telefon blir tom');
nz(jeab_norm_phone('abc') === '', 'utan siffror blir tom');
nz(jeab_norm_phone('+1 202 555 0123') === '', 'utländskt nummer blir tomt');
nz(jeab_norm_phone('08-123 456 78') === '+46812345678', 'riktnummer 08 blir +46');
nz(jeab_inkorg_phone_norm('0046 70 123 45 67') === $mobile && jeab_inkorg_phone_norm('123') === '', 'inkorgens telefon anropar modulen');
nz(jeab_kontakt_phone('070-111 22 33') === '+46701112233', 'kontaktens telefon anropar modulen');
nz(jeab_kontakt_phone('12345') === '', 'ogiltig kontakttelefon sparas inte som siffror');
nz(jeab_normalize_booking_phone('070-123 45 67') === $mobile, 'bokningens mobil');
nz(jeab_normalize_booking_phone('08-123 456 78') === '+46812345678', 'bokningens riktnummer trunkeras inte vid första 7');
nz(jeab_normalize_booking_phone('') === null && jeab_normalize_booking_phone('123') === null, 'tom eller ogiltig bokningstelefon är null');
nz(jeab_normalize_reminder_phone('0046 70-123 45 67') === $mobile, 'påminnelse tar 0046');
$threw = false;
try {
    jeab_normalize_reminder_phone('08-123 456 78');
} catch (InvalidArgumentException) {
    $threw = true;
}
nz($threw, 'påminnelse kräver mobil, inte riktnummer');
nz(jeab_reminder_phone_key('070-123 45 67') === jeab_reminder_phone_key('+46701234567'), 'telefonnyckel är samma för 07 och +46');

nz(jeab_norm_email('  A@B.SE ') === 'a@b.se', 'e-post trimmas och blir gemener');
nz(jeab_norm_email('') === '', 'tom e-post');
nz(jeab_kontakt_email('  Ada@Post.SE ') === 'ada@post.se' && jeab_kontakt_email('inte-en-adress') === '', 'kontaktens e-post kräver @');

nz(jeab_norm_street_key('Västra Gatan 12', '', '') === jeab_norm_street_key('vastra  gatan 12', '', ''), 'gata ignorerar versaler, diakriter och blanksteg');
nz(jeab_norm_street_key('Storgatan', '', '') === '', 'gata utan nummer är ingen nyckel');
nz(jeab_norm_street_key('Testgatan 43A', '177 70', 'Solna') === 'testgatan 43a|17770', '43A och postnummer');
nz(jeab_norm_street_key('Testgatan 43 A', '17770', 'Annanort') === 'testgatan 43a|17770', '43 A är samma som 43A, postnummer slår ort');
nz(jeab_norm_street_key('Testgatan 43', '17770', '') !== jeab_norm_street_key('Testgatan 43A', '17770', ''), '43 är inte 43A');
nz(jeab_norm_street_key('Testgatan 43A lgh 1102', '17770', '') === 'testgatan 43a lgh 1102|17770', 'lägenhetsnummer ingår');
nz(jeab_norm_street_key('Testgatan 43A', '17770', '') !== jeab_norm_street_key('Testgatan 43A lgh 1102', '17770', ''), 'lgh 1102 är inte samma nyckel som huset');
nz(jeab_norm_street_key('Testgatan 43A lägenhet 1102', '', '') === 'testgatan 43a lgh 1102', 'lägenhet skrivs lgh');
nz(jeab_norm_street_key('Norrskensvägen 12', '', 'Solna') !== jeab_norm_street_key('Norrskensvägen 12', '', 'Täby'), 'samma gata i olika orter är olika nycklar');
nz(jeab_norm_street_key('Norrskensvägen 12', '12345', 'Solna') === jeab_norm_street_key('Norrskensvägen 12', '123 45', 'Täby'), 'samma postnummer är samma nyckel');
nz(jeab_norm_street_key('Ljusterö huvuds väg 23', '', '') === jeab_norm_street_key('Ljusteröhuvudsväg 23', '', ''), 'mellanslag i gatunamnet ignoreras');
nz(jeab_kontakt_street_key('Västra Gatan 12') === 'vastragatan 12', 'kontaktens gatunyckel är oförändrad utan lägenhet');
nz(jeab_kontakt_street_key('Testgatan 43A lgh 1102') === 'testgatan 43a lgh 1102', 'kontaktens gatunyckel behåller lägenhet');
nz(jeab_solkoll_street_key('Gymnastikvägen 29, Järfälla') === jeab_solkoll_street_key('Gymnastikvägen 29 Järfälla'), 'Solkoll-nyckeln ignorerar komma och ort');
nz(jeab_solkoll_street_key('Hässelby Strandväg 47 Hässelby') === 'hasselby strandvag 47', 'Solkoll behåller ordmellanrum');

echo "ok\n";
