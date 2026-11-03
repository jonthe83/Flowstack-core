<?php

declare(strict_types=1);

/** Solkampanj: tak 50 000 kr, list_stats och exclude. Ingen sändning. */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-solkampanj-cap-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
mkdir($tmp . '/privat', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_SOLKAMPANJ_DIR'] = $tmp . '/privat/solkampanj';
$GLOBALS['_JEAB_APP_ROOT'] = $tmp . '/public';
mkdir($tmp . '/public', 0775, true);
putenv('JEAB_DISABLE_SQLITE');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'solkampanj-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

function cap_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$mails = 0;
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function () use (&$mails): array {
    $mails++;

    return ['ok' => false, 'path' => '', 'error' => 'ska inte skicka', 'id' => ''];
};

/** @param array<string, array{invoices:list<array<string,mixed>>,customers:list<array<string,mixed>>}> $books */
$install = static function (array $books) : void {
    $GLOBALS['_JEAB_FORTNOX_KUND_FAKTURASUMMOR_FACTORY'] = static function (string $account) use ($books): object {
        $book = $books[$account] ?? ['invoices' => [], 'customers' => []];

        return new class($account, $book['invoices'], $book['customers']) extends FortnoxAPI {
            /** @param list<array<string,mixed>> $invoices @param list<array<string,mixed>> $customers */
            public function __construct(private string $accountName, private array $invoices, private array $customers)
            {
            }

            public function listAll(string $path, string $key, array $query = [], int $sleepUs = 250000): array
            {
                if ($query !== []) {
                    throw new RuntimeException('datumfilter');
                }
                if ($path === '/invoices' && $key === 'Invoices') {
                    return $this->invoices;
                }
                if ($path === '/customers' && $key === 'Customers') {
                    return $this->customers;
                }
                throw new RuntimeException('fel endpoint');
            }
        };
    };
};

$customer = static function (string $number, string $email): array {
    return [
        'CustomerNumber' => $number,
        'Name' => 'Kund ' . $number,
        'Address1' => 'Gatan ' . $number,
        'ZipCode' => '17731',
        'City' => 'Järfälla',
        'Email' => $email,
    ];
};
$invoice = static function (string $number, float $total, bool $credit = false, bool $cancelled = false): array {
    return [
        'CustomerNumber' => $number,
        'InvoiceDate' => '2020-01-02',
        'Total' => $total,
        'TotalToPay' => 1,
        'Cancelled' => $cancelled,
        'Credit' => $credit,
    ];
};

$books = [
    'Jarfalla' => [
        'customers' => [
            $customer('10', 'big@kund.se'),
            $customer('11', 'BIG@kund.se'),
            $customer('14', 'split@kund.se'),
            $customer('16', 'cancel@kund.se'),
            $customer('18', 'credit@kund.se'),
            $customer('20', 'sentover@kund.se'),
            $customer('21', 'pendover@kund.se'),
        ],
        'invoices' => [
            $invoice('10', 30000),
            $invoice('11', 20000),
            $invoice('14', 49000),
            $invoice('16', 1000),
            $invoice('16', 99999, false, true),
            $invoice('18', 51000),
            $invoice('18', 2000, true),
            $invoice('20', 60000),
            $invoice('21', 60000),
        ],
    ],
    'Malmo' => [
        'customers' => [
            $customer('12', 'mid@kund.se'),
            $customer('13', 'mid@kund.se'),
            $customer('15', 'split@kund.se'),
        ],
        'invoices' => [
            $invoice('12', 40000),
            $invoice('13', 9000),
            $invoice('15', 2000),
        ],
    ],
];

$csv = "kundnummer;namn;gata;postnr;ort;mejl\n"
    . "10;Big Berg;A 1;177 31;Järfälla;big@kund.se\n"
    . "14;Split Svensson;B 2;177 32;Järfälla;split@kund.se\n"
    . "16;Cancel Carlsson;C 3;177 33;Järfälla;cancel@kund.se\n"
    . "18;Credit Dahl;D 4;177 34;Järfälla;credit@kund.se\n"
    . "12;Mid Ek;E 5;211 20;Malmö;mid@kund.se\n"
    . "90;None Frisk;F 6;177 35;Järfälla;none@kund.se\n"
    . "20;Sent Over;G 7;177 36;Järfälla;sentover@kund.se\n"
    . "21;Pend Over;H 8;177 37;Järfälla;pendover@kund.se\n";

$GLOBALS['_JEAB_FORTNOX_KUND_FAKTURASUMMOR_FACTORY'] = static function (): object {
    throw new RuntimeException('ska inte anropas');
};
$badMax = jeab_solkampanj_import_dispatch(['csv' => $csv, 'max_total' => -1]);
cap_ok(empty($badMax['ok']) && ($badMax['error'] ?? '') === 'Ogiltigt tak.', 'ogiltigt tak stoppar innan Fortnox');
cap_ok(!is_file($GLOBALS['_JEAB_SOLKAMPANJ_DIR'] . '/recipients.csv'), 'ogiltigt tak skriver ingen lista');

$down = jeab_solkampanj_import_dispatch(['csv' => "kundnummer;namn;gata;postnr;ort;mejl\n1;A A;A 1;177 31;Järfälla;a@kund.se\n"]);
cap_ok(empty($down['ok']) && ($down['error'] ?? '') === 'Fakturorna gick inte att hämta.', 'Fortnox-fel stoppar importen');
jeab_solkampanj_ensure(jeab_solkampanj_pdo());
cap_ok((int) jeab_solkampanj_pdo()->query('SELECT COUNT(*) FROM solkampanj_recipients')->fetchColumn() === 0, 'misslyckad hämtning lämnar listan tom');

$install($books);
jeab_solkampanj_import_csv("kundnummer;namn;gata;postnr;ort;mejl\n20;Sent Over;G 7;177 36;Järfälla;sentover@kund.se\n21;Pend Over;H 8;177 37;Järfälla;pendover@kund.se\n");
$pdo = jeab_solkampanj_pdo();
$stamp = '2020-05-01T12:00:00+00:00';
$pdo->prepare("UPDATE solkampanj_recipients SET status = 'sent', sent_at = ?, sent_by = 'Sam' WHERE mejl = 'sentover@kund.se'")->execute([$stamp]);

$saved = jeab_solkampanj_import_dispatch(['csv' => $csv]);
cap_ok(!empty($saved['ok']) && (float) ($saved['max_total'] ?? 0) === 50000.0, 'taket är 50 000 när det utelämnas');
cap_ok(($saved['imported'] ?? -1) === 4 && ($saved['updated'] ?? -1) === 2 && ($saved['capped'] ?? -1) === 3 && ($saved['skipped'] ?? -1) === 2, 'nya över taket hoppas över och väntande vänds');

$row = static function (string $email) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT id, status, sent_at, sent_by FROM solkampanj_recipients WHERE mejl = ?');
    $stmt->execute([$email]);
    $found = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($found) ? $found : [];
};
$big = $row('big@kund.se');
$split = $row('split@kund.se');
$mid = $row('mid@kund.se');
$cancel = $row('cancel@kund.se');
$credit = $row('credit@kund.se');
$none = $row('none@kund.se');
$sent = $row('sentover@kund.se');
$pend = $row('pendover@kund.se');
cap_ok(($big['status'] ?? '') === 'skipped' && ($big['sent_by'] ?? '') === JEAB_SOLKAMPANJ_EXCLUDE_BY && ($big['sent_at'] ?? null) === null, 'exakt 50 000 räknas som över');
cap_ok(($split['status'] ?? '') === 'skipped' && ($split['sent_by'] ?? '') === JEAB_SOLKAMPANJ_EXCLUDE_BY, 'summa per mejl över båda bolagen');
cap_ok(($mid['status'] ?? '') === 'pending' && ($cancel['status'] ?? '') === 'pending' && ($credit['status'] ?? '') === 'pending' && ($none['status'] ?? '') === 'pending', 'under taket, makulerat, kredit och okänd mejl stannar');
cap_ok(($sent['status'] ?? '') === 'sent' && ($sent['sent_by'] ?? '') === 'Sam' && ($sent['sent_at'] ?? '') === $stamp, 'skickad rad rörs inte');
cap_ok(($pend['status'] ?? '') === 'skipped' && ($pend['sent_by'] ?? '') === JEAB_SOLKAMPANJ_EXCLUDE_BY, 'väntande över taket blir överhoppad');

$again = jeab_solkampanj_import_dispatch(['csv' => $csv]);
$bigAgain = $row('big@kund.se');
$sentAgain = $row('sentover@kund.se');
cap_ok(($again['imported'] ?? -1) === 0 && ($again['capped'] ?? -1) === 0 && (int) $pdo->query('SELECT COUNT(*) FROM solkampanj_recipients')->fetchColumn() === 8, 'omimport lägger inte till raderna igen');
cap_ok(($bigAgain['status'] ?? '') === 'skipped' && ($bigAgain['sent_by'] ?? '') === JEAB_SOLKAMPANJ_EXCLUDE_BY, 'omimport väcker inte en överhoppad rad');
cap_ok(($sentAgain['status'] ?? '') === 'sent' && ($sentAgain['sent_by'] ?? '') === 'Sam' && ($sentAgain['sent_at'] ?? '') === $stamp, 'omimport lämnar skickat orört');

$before = $pdo->query('SELECT id, status, sent_at, sent_by FROM solkampanj_recipients ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$stats = jeab_solkampanj_list_stats();
$after = $pdo->query('SELECT id, status, sent_at, sent_by FROM solkampanj_recipients ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
cap_ok($before === $after && ($stats['read_only'] ?? 0) === 1, 'list_stats skriver inget');
cap_ok(($stats['statuses']['pending'] ?? -1) === 4 && ($stats['statuses']['sent'] ?? -1) === 1 && ($stats['statuses']['skipped'] ?? -1) === 3, 'antal per status');
$towns = [];
foreach ($stats['towns'] ?? [] as $town) {
    if (is_array($town)) {
        $towns[(string) ($town['ort'] ?? '')] = $town;
    }
}
cap_ok(array_key_first($towns) === 'Järfälla' && ($towns['Järfälla']['pending'] ?? -1) === 3 && ($towns['Järfälla']['sent'] ?? -1) === 1 && ($towns['Järfälla']['skipped'] ?? -1) === 3, 'Järfälla per status');
cap_ok(($towns['Malmö']['pending'] ?? -1) === 1 && ($towns['Malmö']['total'] ?? -1) === 1, 'Malmö per ort');

$dry = jeab_solkampanj_exclude([
    'emails' => 'MID@kund.se, big@kund.se',
    'ids' => (string) ($sent['id'] ?? '0') . ',' . (string) ($pend['id'] ?? '0'),
]);
cap_ok(!empty($dry['ok']) && ($dry['dry'] ?? 0) === 1 && ($dry['affected'] ?? null) === [(int) $mid['id']], 'dry är standard och tar bara väntande');
cap_ok(($dry['undo'] ?? null) === [['id' => (int) $mid['id'], 'status' => 'pending', 'sent_at' => null, 'sent_by' => '']], 'ångra-listan är radens läge');
cap_ok(($row('mid@kund.se')['status'] ?? '') === 'pending' && $mails === 0, 'dry skriver inte och skickar inte');

$unconfirmed = jeab_solkampanj_exclude(['dry' => 0, 'id' => (int) $mid['id']]);
cap_ok(empty($unconfirmed['ok']) && ($unconfirmed['error'] ?? '') === 'Bekräfta med confirm=1.' && ($row('mid@kund.se')['status'] ?? '') === 'pending', 'verklig körning kräver confirm=1');

$live = jeab_solkampanj_exclude(['dry' => '0', 'confirm' => '1', 'email' => 'mid@kund.se', 'ids' => [(int) $sent['id']]]);
$midNow = $row('mid@kund.se');
$sentNow = $row('sentover@kund.se');
cap_ok(!empty($live['ok']) && ($live['dry'] ?? 1) === 0 && ($live['affected'] ?? null) === [(int) $mid['id']], 'confirm tar väntande mejl');
cap_ok(($midNow['status'] ?? '') === 'skipped' && ($midNow['sent_by'] ?? '') === JEAB_SOLKAMPANJ_EXCLUDE_BY && ($midNow['sent_at'] ?? null) === null, 'väntande blir överhoppad med markören');
cap_ok(($sentNow['status'] ?? '') === 'sent' && ($sentNow['sent_by'] ?? '') === 'Sam' && ($sentNow['sent_at'] ?? '') === $stamp && $mails === 0, 'skickat och utskickshistorik rörs inte');

$repeat = jeab_solkampanj_exclude(['dry' => false, 'confirm' => 1, 'emails' => ['mid@kund.se', 'big@kund.se'], 'id' => (int) $mid['id']]);
cap_ok(!empty($repeat['ok']) && ($repeat['affected'] ?? null) === [] && ($repeat['undo'] ?? null) === [], 'samma körning igen ändrar inget');

$empty = jeab_solkampanj_exclude(['emails' => 'inte-en-mejl', 'ids' => '0']);
cap_ok(empty($empty['ok']) && ($empty['error'] ?? '') === 'Ange mejl eller id.', 'tomt urval avvisas');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$at = strpos($hook, "=== 'solkampanj.list_stats'");
$slice = $at === false ? '' : substr($hook, $at, 1400);
$authAt = strpos($slice, 'jeab_inkorg_hook_authorized');
$excludeAt = strpos($slice, 'jeab_solkampanj_exclude');
cap_ok($at !== false && $authAt !== false && $excludeAt !== false && $authAt < $excludeAt && str_contains($slice, 'jeab_solkampanj_list_stats'), 'hookarna kräver token');
$srcAt = strpos((string) file_get_contents(dirname(__DIR__) . '/src/solkampanj.php'), 'function jeab_solkampanj_exclude(');
$srcSlice = $srcAt === false ? '' : substr((string) file_get_contents(dirname(__DIR__) . '/src/solkampanj.php'), $srcAt, 2200);
cap_ok($srcSlice !== '' && !str_contains($srcSlice, 'jeab_system_mail') && !str_contains($srcSlice, 'jeab_solkampanj_send') && !str_contains($srcSlice, 'send_guard'), 'exclude skriver bara mottagarraden');

echo "solkampanj cap: all passed\n";
