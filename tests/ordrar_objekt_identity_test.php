<?php

declare(strict_types=1);

/**
 * Objekt på statussidan: personnummer och fastighetsbeteckning.
 * Sparas på samma fält som Fortnox läser. Normalisering till ÅÅÅÅMMDD-XXXX.
 * Lantmäteriet-förslaget fyller bara tomma fält, aldrig över sparat.
 * Sparningen bokar inte, mejlar inte, sms:ar inte och rör inte Fortnox.
 * Inget riktigt nätverksanrop: LantmaterietAPI är en stubbfil.
 */

$tmp = sys_get_temp_dir() . '/jeab-objekt-id-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';

function oid_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function oid_throws(callable $fn, string $needle): bool
{
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return str_contains($e->getMessage(), $needle);
    }
    return false;
}

$mails = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$mails): array {
    $mails[] = $opts;
    return ['ok' => true, 'path' => 'stub', 'error' => null, 'id' => 'stub'];
};

// --- Normalisering ---
$n = 'jeab_ordrar_normalize_objekt_pnr';
oid_assert($n('19800101-1234') === '19800101-1234', '12 siffror med bindestreck');
oid_assert($n('198001011234') === '19800101-1234', '12 siffror utan bindestreck');
oid_assert($n('800101-1234') === '19800101-1234', '10 siffror med bindestreck');
oid_assert($n('8001011234') === '19800101-1234', '10 siffror utan bindestreck');
oid_assert($n(' 19800101 - 1234 ') === '19800101-1234', 'mellanslag tas bort');
oid_assert($n('100101-1234') === '20100101-1234', '10 siffror nära nu blir 20xx');
oid_assert($n('200101+1234') === '19200101-1234', 'plustecken betyder hundra år till');
oid_assert($n('19800161-1234') === '19800161-1234', 'samordningsnummer (dag+60) godtas');
oid_assert($n('') === '' && $n('   ') === '', 'tomt blir tomt');
oid_assert(oid_throws(static fn() => $n('19800101-123'), '12 siffror'), '11 siffror avvisas med antal');
oid_assert(oid_throws(static fn() => $n('1980010112345'), 'fick 13 siffror'), '13 siffror avvisas med antal');
oid_assert(oid_throws(static fn() => $n('abc'), 'bara ha siffror'), 'bokstäver avvisas');
oid_assert(oid_throws(static fn() => $n('1980-0101-1234'), 'bara ha siffror'), 'två bindestreck avvisas');
oid_assert(oid_throws(static fn() => $n('19801301-1234'), 'datum'), 'månad 13 avvisas');
oid_assert(oid_throws(static fn() => $n('19800100-1234'), 'datum'), 'dag 00 avvisas');
oid_assert(
    str_starts_with(jeab_ordrar_save_fail_text('Personnummer ska vara 12 siffror, ÅÅÅÅMMDD-XXXX (fick 9 siffror).'), 'Kunde inte spara. Personnummer'),
    'felet visas som «Kunde inte spara.» plus orsak'
);

// --- Sparning ---
jeab_ordrar_external_live(false);
$base = [
    'reminder_id' => 5151,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Guy Nilsson',
    'huvudsokande' => 'Guy Nilsson',
    'personnummer' => '',
    'fastighetsbeteckning' => '',
    'address' => 'Gymnastikvägen 29, 175 00 Järfälla',
    'huvudsokande_address' => 'Gymnastikvägen 29, 175 00 Järfälla',
    'natbolag' => 'Ellevio',
    'phone' => '+46701234567',
    'email' => 'guy@example.com',
    'steps' => [],
];
jeab_purchase_put($base);
$objekt = [
    'id' => 5151,
    'objekt_adress' => 'Gymnastikvägen 29',
    'objekt_postnummer' => '175 00',
    'objekt_postort' => 'Järfälla',
    'phone' => '0701234567',
    'email' => 'guy@example.com',
    'natbolag' => 'Ellevio',
];

$saved = jeab_ordrar_save_objekt($objekt + [
    'objekt_personnummer' => '8001011234',
    'objekt_fastighetsbeteckning' => '  Järfälla Gymnasten 7  ',
]);
oid_assert(($saved['personnummer'] ?? '') === '', 'Objekt skriver inte personnummer');
oid_assert(($saved['fastighetsbeteckning'] ?? '') === 'Järfälla Gymnasten 7', 'fastighetsbeteckning trimmas på row[fastighetsbeteckning]');
$again = jeab_purchase_get(5151);
oid_assert(($again['personnummer'] ?? '') === '', 'personnummer ligger inte på ordern efter Objekt-sparning');
oid_assert(($again['fastighetsbeteckning'] ?? '') === 'Järfälla Gymnasten 7', 'fastighet ligger kvar efter omläsning');
$withOwner = $again;
$withOwner['huvudsokande'] = 'Guy Nilsson';
$withOwner['owners'] = [['name' => 'Guy Nilsson', 'personnummer' => '19800101-1234', 'address' => 'Gymnastikvägen 29']];
oid_assert(!in_array('personnummer', jeab_ordrar_fortnox_missing($withOwner), true), 'Fortnox ser personnumret på ägarkortet');
oid_assert(!in_array('fastighetsbeteckning', jeab_ordrar_fortnox_missing($again), true), 'Fortnox ser fastigheten');

$bad = oid_throws(static fn() => jeab_ordrar_save_objekt(array_merge($objekt, [
    'objekt_fastighetsbeteckning' => 'ANNAN 1:1',
    'email' => 'inte-en-adress',
])), 'e-post');
oid_assert($bad, 'ogiltig e-post stoppar sparningen');
$afterBad = jeab_purchase_get(5151);
oid_assert(($afterBad['fastighetsbeteckning'] ?? '') === 'Järfälla Gymnasten 7', 'avvisad sparning rör inte fastigheten');
oid_assert(($afterBad['email'] ?? '') === 'guy@example.com', 'avvisad sparning sparar inget annat heller');

$noKeys = jeab_ordrar_save_objekt($objekt);
oid_assert(($noKeys['fastighetsbeteckning'] ?? '') === 'Järfälla Gymnasten 7', 'formulär utan fastighetsfält lämnar fastigheten');

$cleared = jeab_ordrar_save_objekt($objekt + ['objekt_fastighetsbeteckning' => ' ']);
oid_assert(($cleared['fastighetsbeteckning'] ?? 'x') === '', 'tömd fastighet kan sparas tom');

// --- Inga sidoeffekter ---
oid_assert($mails === [], 'inget mejl');
oid_assert(trim((string) ($cleared['fortnox_order'] ?? '')) === '', 'ingen Fortnox-order');
oid_assert((int) ($cleared['booking_id'] ?? 0) === 0, 'ingen bokning på ordern');
oid_assert(jeab_store()->listAllBookings() === [], 'inga bokningar i kalendern');
oid_assert(!is_file(jeab_sms_sent_path()), 'inget sms registrerat');
$index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
oid_assert(str_contains($index, "&& (string) (\$_POST['action'] ?? '') !== 'ordrar.objekt'"), 'misslyckad Objekt-POST bokar inte vid omritning');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$start = strpos($src, 'function jeab_ordrar_save_objekt(');
$end = strpos($src, "\nfunction ", (int) $start + 10);
$body = substr($src, (int) $start, (int) $end - (int) $start);
foreach (['/create_booking\s*\(/', '/\w*fortnox\w*\s*\(/i', '/sms_send\s*\(/', '/system_mail\w*\s*\(/', '/email_send\s*\(/', '/notify\w*\s*\(/', '/update_booking\s*\(/'] as $bad) {
    oid_assert(preg_match($bad, $body) !== 1, 'save_objekt anropar inte ' . $bad);
}

// --- Markup: samma Objekt-rad, ingen ny primärknapp ---
jeab_purchase_put(array_merge($base, ['personnummer' => '19800101-1234', 'fastighetsbeteckning' => 'JÄRFÄLLA GYMNASTEN 7']));
$html = jeab_ordrar_status_html((array) jeab_purchase_get(5151), 'jarfalla', 5151, null);
oid_assert(!str_contains($html, 'objekt_personnummer'), 'Objekt har inget personnummerfält');
oid_assert(!str_contains($html, '>Personnr<'), 'Objekt visar inte Personnr');
oid_assert(str_contains($html, 'name="objekt_fastighetsbeteckning" value="JÄRFÄLLA GYMNASTEN 7"'), 'Objekt visar fastighetsbeteckning');
oid_assert(substr_count($html, '<label class="ordrar-objekt-line">') === 4, 'fälten använder samma ordrar-objekt-line som mobil/e-post/nätägare');
$formStart = (int) strpos($html, 'ordrar-objekt-form');
$formHtml = substr($html, $formStart, (int) strpos($html, '</form>', $formStart) - $formStart);
oid_assert(substr_count($formHtml, 'ordrar-primary') === 1, 'fortfarande bara en primärknapp i Objekt');
oid_assert(strpos($html, 'objekt_fastighetsbeteckning') > strpos($html, 'name="email"') && strpos($html, 'objekt_fastighetsbeteckning') < strpos($html, 'name="natbolag"'), 'fastighet står bland de andra Objekt-fälten');

$GLOBALS['_JEAB_OBJEKT_POSTED'] = ['fastighetsbeteckning' => 'X 1:1'];
$errHtml = jeab_ordrar_status_html((array) jeab_purchase_get(5151), 'jarfalla', 5151, null);
oid_assert(str_contains($errHtml, 'name="objekt_fastighetsbeteckning" value="X 1:1"'), 'efter fel står den inskrivna fastigheten kvar att rätta');
oid_assert(!str_contains($errHtml, 'objekt_personnummer'), 'felrutan har inget personnummerfält');
unset($GLOBALS['_JEAB_OBJEKT_POSTED']);
oid_assert(!array_key_exists('_objekt_posted', (array) jeab_purchase_get(5151)), 'inskrivet felvärde hamnar aldrig på ordern');

// --- Förslag från Lantmäteriet (stubb) ---
$rotDir = $tmp . '/rot';
@mkdir($rotDir, 0775, true);
file_put_contents($rotDir . '/data_scraping.php', <<<'PHP'
<?php
class LantmaterietAPI {
    public $data = [];
    public function search($addressText, $uuid = '') {
        if (str_contains($addressText, 'Brf')) {
            return false;
        }
        $this->data = [
            'fastighetsbeteckning' => 'Testby 1:2',
            'matchedAddress' => 'Teststad Testgatan 1',
            'owners' => [
                ['name' => 'Evangelia Maria Testou', 'personnummer' => '197001011234', 'address' => 'Testgatan 1, 123 45 Teststad', 'andel' => '1/1'],
            ],
        ];
        return true;
    }
}
PHP);
$GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES'] = [$rotDir . '/data_scraping.php'];
jeab_ordrar_external_live(true);

$emptyRow = [
    'reminder_id' => 999101,
    'address' => 'Testgatan 1, Teststad',
    'huvudsokande' => 'Evangelia Testou',
    'huvudsokande_manual' => true,
    'personnummer' => '',
    'fastighetsbeteckning' => '',
];
$pre = jeab_ordrar_status_identity($emptyRow, 999101);
oid_assert($pre['personnummer'] === '19700101-1234', 'tomt personnummer får förslag från uppslaget');
oid_assert($pre['fastighetsbeteckning'] === 'TESTBY 1:2', 'tom fastighet får förslag från uppslaget');

$keep = jeab_ordrar_status_identity(array_merge($emptyRow, [
    'personnummer' => '19800101-1234',
    'fastighetsbeteckning' => 'EGEN 3:3',
]), 999101);
oid_assert($keep['personnummer'] === '19800101-1234', 'sparat personnummer skrivs inte över av uppslaget');
oid_assert($keep['fastighetsbeteckning'] === 'EGEN 3:3', 'sparad fastighet skrivs inte över av uppslaget');

$half = jeab_ordrar_status_identity(array_merge($emptyRow, ['personnummer' => '19800101-1234']), 999101);
oid_assert($half['personnummer'] === '19800101-1234' && $half['fastighetsbeteckning'] === 'TESTBY 1:2', 'bara det tomma fältet fylls');

$brf = jeab_ordrar_status_identity([
    'reminder_id' => 999102,
    'address' => 'Brfgatan 2, Teststad',
    'huvudsokande' => 'Evangelia Testou',
    'personnummer' => '',
    'fastighetsbeteckning' => '',
], 999102);
oid_assert($brf['personnummer'] === '' && $brf['fastighetsbeteckning'] === '', 'utan träff (bostadsrätt) står fälten tomma och redigerbara');

oid_assert($mails === [], 'fortfarande inget mejl');
unset($GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES']);

echo "ordrar objekt identity: ok\n";
