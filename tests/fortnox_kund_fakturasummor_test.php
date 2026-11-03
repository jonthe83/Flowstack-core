<?php

declare(strict_types=1);

/** fortnox.kund_fakturasummor: läser fakturor och kunder, summerar, filtrerar. Ingen skrivning. */

$tmp = sys_get_temp_dir() . '/jeab-ffs-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
mkdir($tmp . '/public', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox_kund_fakturasummor.php';

function ffs(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$inboxToken = 'ink_' . bin2hex(random_bytes(8));
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => $inboxToken]));

$allowed = [
    'customer_number', 'namn', 'gata', 'postnr', 'ort', 'mejl', 'mobil',
    'invoice_count', 'sum_total_inkl_moms', 'sum_tax_reduction', 'sum_to_pay', 'senaste_fakturadatum',
];
$secretMarker = '19121212-1212';
$leakMarker = 'LEAK-MARKER-PNR';

$bag = new stdClass();
$bag->calls = [];
$install = static function (array $invoices, array $customers) use (&$bag): void {
    $bag = new stdClass();
    $bag->calls = [];
    $GLOBALS['_JEAB_FORTNOX_KUND_FAKTURASUMMOR_FACTORY'] = static function (string $account) use ($invoices, $customers, $bag): FortnoxAPI {
        return new class($account, $invoices, $customers, $bag) extends FortnoxAPI {
            /** @param list<mixed> $invoices @param list<mixed> $customers */
            public function __construct(private string $accountName, private array $invoices, private array $customers, private stdClass $bag)
            {
            }

            public function listAll(string $path, string $key, array $query = [], int $sleepUs = 250000): array
            {
                $this->bag->calls[] = [
                    'account' => $this->accountName,
                    'path' => $path,
                    'key' => $key,
                    'query' => $query,
                    'argc' => func_num_args(),
                ];
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

$auth = static function (bool $on) use ($inboxToken): void {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    if ($on) {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $inboxToken;
        return;
    }
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_WEBHOOK_TOKEN'], $_POST['token']);
};

$invoices = [
    [
        'CustomerNumber' => '10',
        'InvoiceDate' => '2024-01-15',
        'Total' => 40000,
        'TaxReduction' => 5000,
        'TotalToPay' => 35000,
        'Balance' => 1000,
        'Cancelled' => false,
        'Credit' => false,
        'InvoiceType' => 'INVOICE',
    ],
    [
        'CustomerNumber' => '10',
        'InvoiceDate' => '2024-06-01',
        'Total' => 2000,
        'TaxReduction' => 200,
        'TotalToPay' => 1800,
        'Cancelled' => false,
        'Credit' => true,
    ],
    [
        'CustomerNumber' => '10',
        'InvoiceDate' => '2024-03-01',
        'Total' => 99999,
        'TaxReduction' => 1000,
        'TotalToPay' => 99999,
        'Cancelled' => true,
        'Credit' => false,
    ],
    [
        'CustomerNumber' => '20',
        'InvoiceDate' => '2024-12-31',
        'Total' => 80000,
        'TaxReduction' => 0,
        'TotalToPay' => 80000,
        'Cancelled' => 'false',
        'Credit' => false,
    ],
    [
        'CustomerNumber' => '30',
        'InvoiceDate' => '2024-02-02',
        'Total' => -400,
        'TaxReduction' => 0,
        'TotalToPay' => -400,
        'Cancelled' => false,
        'InvoiceType' => 'CREDITINVOICE',
    ],
    [
        'CustomerNumber' => '40',
        'InvoiceDate' => '2023-01-01',
        'Total' => 100,
        'TaxReduction' => 0,
        'TotalToPay' => 100,
        'Cancelled' => false,
        'Credit' => false,
    ],
    [
        'CustomerNumber' => '50',
        'InvoiceDate' => '2024-04-04',
        'Total' => 1000,
        'TaxReduction' => 0,
        'Balance' => 900,
        'Cancelled' => 0,
        'Credit' => false,
    ],
    [
        'CustomerNumber' => '60',
        'InvoiceDate' => '2024-05-05',
        'Total' => 50000,
        'TaxReduction' => 0,
        'TotalToPay' => 50000,
        'Cancelled' => false,
        'Credit' => false,
    ],
    [
        'CustomerNumber' => '',
        'InvoiceDate' => '2024-01-01',
        'Total' => 10,
        'TotalToPay' => 10,
        'Cancelled' => false,
    ],
    'skip-me',
];

$customers = [
    [
        'CustomerNumber' => '10',
        'Name' => 'Test ' . $secretMarker . ' Kund',
        'Address1' => 'Testgatan 1',
        'ZipCode' => '11122',
        'City' => 'Järfälla',
        'Email' => 'test@example.com',
        'Phone1' => '08-111 22 33',
        'Phone2' => '070-111 22 33',
        'Comments' => $secretMarker,
    ],
    [
        'CustomerNumber' => 20,
        'Name' => 'Stor Kund',
        'Address1' => 'Storgatan 2',
        'ZipCode' => '22233',
        'City' => 'Malmö',
        'Email' => 'stor@example.com',
        'Phone1' => '0701234567',
        'Phone2' => '08-222 33 44',
    ],
    [
        'CustomerNumber' => '30',
        'Name' => 'Kredit Kund',
        'Address1' => 'Kreditvägen 3',
        'ZipCode' => '33344',
        'City' => 'Lund',
        'Email' => 'kredit@example.com',
        'Phone1' => '0703334455',
        'Phone2' => '',
    ],
    [
        'CustomerNumber' => '40',
        'Name' => 'Gammal Kund',
        'Address1' => 'Gamla vägen 4',
        'ZipCode' => '44455',
        'City' => 'Uppsala',
        'Email' => 'gammal@example.com',
        'Phone1' => '018-123456',
        'Phone2' => '',
    ],
    [
        'CustomerNumber' => '50',
        'Name' => 'Saldo Kund',
        'Address1' => 'Saldogatan 5',
        'ZipCode' => '55566',
        'City' => 'Västerås',
        'Email' => 'saldo@example.com',
        'Phone1' => '021-123456',
        'Phone2' => '073-555 66 77',
    ],
    [
        'CustomerNumber' => '60',
        'Name' => 'Tak Kund',
        'Address1' => 'Takgatan 6',
        'ZipCode' => '66677',
        'City' => 'Örebro',
        'Email' => 'tak@example.com',
        'Phone1' => '0706667788',
        'Phone2' => '',
    ],
    'skip-customer',
];

$byNumber = static function (array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        $out[(string) $row['customer_number']] = $row;
    }
    return $out;
};

$auth(true);
$install($invoices, $customers);
$ok = jeab_fortnox_kund_fakturasummor(['action' => 'fortnox.kund_fakturasummor', 'account' => 'Jarfalla']);
ffs(($ok['ok'] ?? false) === true && ($ok['read_only'] ?? 0) === 1, 'svaret är ok och read_only');
ffs(($ok['account'] ?? '') === 'Jarfalla', 'account är Jarfalla');
ffs(count($bag->calls) === 2 && $bag->calls[0]['path'] === '/invoices' && $bag->calls[0]['key'] === 'Invoices' && $bag->calls[0]['argc'] === 2 && $bag->calls[0]['query'] === [] && $bag->calls[0]['account'] === 'Jarfalla', 'listAll /invoices Invoices utan datum');
ffs($bag->calls[1]['path'] === '/customers' && $bag->calls[1]['key'] === 'Customers' && $bag->calls[1]['argc'] === 2, 'listAll /customers Customers');
$encoded = json_encode($ok, JSON_UNESCAPED_UNICODE);
ffs(is_string($encoded) && !str_contains($encoded, $secretMarker) && !str_contains($encoded, 'Comments'), 'personnummer och övriga fält lämnas ute');
ffs(($ok['period']['first_invoice_date'] ?? '') === '2023-01-01' && ($ok['period']['last_invoice_date'] ?? '') === '2024-12-31', 'perioden är första och sista räknade fakturan');
$rows = $byNumber($ok['rows'] ?? []);
ffs(isset($rows['10'], $rows['30'], $rows['40'], $rows['50']) && !isset($rows['20'], $rows['60']), 'taket släpper igenom bara under 50000 att betala');
ffs(($ok['count'] ?? -1) === 4 && count($ok['rows']) === 4, 'count är de filtrerade raderna');
ffs(array_keys($rows['10']) === $allowed, 'raden har bara vitlistade nycklar');
ffs($rows['10']['namn'] === 'Test Kund' && $rows['10']['gata'] === 'Testgatan 1' && $rows['10']['postnr'] === '11122' && $rows['10']['ort'] === 'Järfälla' && $rows['10']['mejl'] === 'test@example.com', 'kunden joinar namn, gata, postnr, ort och mejl');
ffs($rows['10']['mobil'] === '070-111 22 33', 'Phone2 används när det är ett mobilnummer');
ffs($rows['10']['invoice_count'] === 2 && $rows['10']['sum_total_inkl_moms'] === 38000.0 && $rows['10']['sum_tax_reduction'] === 4800.0 && $rows['10']['sum_to_pay'] === 33200.0 && $rows['10']['senaste_fakturadatum'] === '2024-06-01', 'kredit dras av, makulerad hoppas över');
ffs($rows['30']['sum_total_inkl_moms'] === -400.0 && $rows['30']['sum_to_pay'] === -400.0 && $rows['30']['mobil'] === '0703334455', 'kreditfaktura utan Credit-flagga räknas negativt och Phone1 blir mobil');
ffs($rows['50']['sum_to_pay'] === 900.0 && $rows['50']['mobil'] === '073-555 66 77', 'Balance används när TotalToPay saknas');
ffs(array_keys($ok['rows']) === [0, 1, 2, 3] && $ok['rows'][0]['customer_number'] === '10' && $ok['rows'][3]['customer_number'] === '50', 'raderna sorteras på kundnummer');

$auth(true);
$install($invoices, $customers);
$totalBasis = jeab_fortnox_kund_fakturasummor([
    'action' => 'fortnox.kund_fakturasummor',
    'account' => 'Malmo',
    'basis' => 'total',
    'max_total' => 50000,
]);
$totalRows = $byNumber($totalBasis['rows'] ?? []);
ffs(($totalBasis['account'] ?? '') === 'Malmo' && $bag->calls[0]['account'] === 'Malmo', 'Malmo skapar klienten för Malmo');
ffs(isset($totalRows['10'], $totalRows['30'], $totalRows['40'], $totalRows['50']) && !isset($totalRows['20'], $totalRows['60']), 'basis total filtrerar på summa inkl moms');

$auth(true);
$install($invoices, $customers);
$dated = jeab_fortnox_kund_fakturasummor([
    'action' => 'fortnox.kund_fakturasummor',
    'account' => 'Jarfalla',
    'from_date' => '2024-01-01',
    'to_date' => '2024-06-30',
    'max_total' => 100000,
]);
$datedRows = $byNumber($dated['rows'] ?? []);
ffs($bag->calls[0]['argc'] === 3 && $bag->calls[0]['query'] === ['fromdate' => '2024-01-01', 'todate' => '2024-06-30'], 'datum skickas som fromdate och todate');
ffs(!isset($datedRows['40']) && !isset($datedRows['20']) && ($dated['period']['first_invoice_date'] ?? '') === '2024-01-15' && ($dated['period']['last_invoice_date'] ?? '') === '2024-06-01', 'utanför perioden räknas inte');
ffs(isset($datedRows['60']) && $datedRows['60']['sum_to_pay'] === 50000.0, 'exakt taket ingår när max_total är högre');

$auth(true);
$install($invoices, $customers);
$exact = jeab_fortnox_kund_fakturasummor(['action' => 'fortnox.kund_fakturasummor', 'account' => 'Jarfalla', 'max_total' => '50000']);
$exactRows = $byNumber($exact['rows'] ?? []);
ffs(!isset($exactRows['60']) && isset($exactRows['10']), 'basis under max_total är strikt');

$auth(true);
$install([
    ['CustomerNumber' => '70', 'InvoiceDate' => '2024-07-07', 'Total' => 10, 'TotalToPay' => 10, 'Cancelled' => false, 'Credit' => false],
], []);
$missingCustomer = jeab_fortnox_kund_fakturasummor(['action' => 'fortnox.kund_fakturasummor', 'account' => 'Jarfalla', 'max_total' => 100]);
ffs(($missingCustomer['rows'][0]['customer_number'] ?? '') === '70' && ($missingCustomer['rows'][0]['namn'] ?? 'x') === '' && ($missingCustomer['rows'][0]['mobil'] ?? 'x') === '', 'saknad kund lämnar tomma kontaktfält');

$auth(true);
$install([], []);
$empty = jeab_fortnox_kund_fakturasummor(['action' => 'fortnox.kund_fakturasummor', 'account' => 'Jarfalla']);
ffs(($empty['ok'] ?? false) === true && ($empty['count'] ?? -1) === 0 && ($empty['period']['first_invoice_date'] ?? 'x') === '' && ($empty['period']['last_invoice_date'] ?? 'x') === '', 'inga fakturor ger tom period');

foreach (['', 'jarfalla', 'Stockholm'] as $bad) {
    $auth(true);
    $install($invoices, $customers);
    $denied = jeab_fortnox_kund_fakturasummor(['action' => 'fortnox.kund_fakturasummor', 'account' => $bad]);
    ffs(($denied['ok'] ?? true) === false && ($denied['error'] ?? '') === 'Ogiltigt bolag.' && ($denied['status'] ?? 0) === 400, 'ogiltigt bolag avvisas');
    ffs($bag->calls === [], 'ogiltigt bolag anropar inte Fortnox');
}

foreach (['TOTAL', 'balance', 'to-pay'] as $badBasis) {
    $auth(true);
    $install($invoices, $customers);
    $deniedBasis = jeab_fortnox_kund_fakturasummor(['action' => 'fortnox.kund_fakturasummor', 'account' => 'Jarfalla', 'basis' => $badBasis]);
    ffs(($deniedBasis['ok'] ?? true) === false && ($deniedBasis['error'] ?? '') === 'Ogiltig basis.' && $bag->calls === [], 'ogiltig basis avvisas');
}

$auth(true);
$install($invoices, $customers);
$badMax = jeab_fortnox_kund_fakturasummor(['action' => 'fortnox.kund_fakturasummor', 'account' => 'Jarfalla', 'max_total' => -1]);
ffs(($badMax['ok'] ?? true) === false && ($badMax['error'] ?? '') === 'Ogiltigt tak.' && $bag->calls === [], 'negativt tak avvisas');

$auth(true);
$install($invoices, $customers);
$badDate = jeab_fortnox_kund_fakturasummor(['action' => 'fortnox.kund_fakturasummor', 'account' => 'Jarfalla', 'from_date' => '2024-13-01']);
ffs(($badDate['ok'] ?? true) === false && ($badDate['error'] ?? '') === 'Ogiltigt datum.' && $bag->calls === [], 'ogiltigt datum avvisas');

$auth(true);
$install($invoices, $customers);
$reversed = jeab_fortnox_kund_fakturasummor(['action' => 'fortnox.kund_fakturasummor', 'account' => 'Jarfalla', 'from_date' => '2024-06-01', 'to_date' => '2024-01-01']);
ffs(($reversed['ok'] ?? true) === false && ($reversed['error'] ?? '') === 'Ogiltigt datum.' && $bag->calls === [], 'omvänt intervall avvisas');

$auth(false);
$install($invoices, $customers);
$unauth = jeab_fortnox_kund_fakturasummor(['action' => 'fortnox.kund_fakturasummor', 'account' => 'Jarfalla']);
ffs(($unauth['ok'] ?? true) === false && ($unauth['error'] ?? '') === 'Fel token.' && ($unauth['status'] ?? 0) === 401 && $bag->calls === [], 'anrop utan token avvisas');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-the-inbox-token';
$install($invoices, $customers);
$incoming = jeab_fortnox_kund_fakturasummor(['action' => 'fortnox.kund_fakturasummor', 'account' => 'Jarfalla']);
ffs(($incoming['ok'] ?? true) === false && ($incoming['error'] ?? '') === 'Fel token.' && $bag->calls === [], 'inkommande webhook-token räcker inte');

$auth(true);
$GLOBALS['_JEAB_FORTNOX_KUND_FAKTURASUMMOR_FACTORY'] = static function (string $account) use ($leakMarker): FortnoxAPI {
    return new class($leakMarker) extends FortnoxAPI {
        public function __construct(private string $marker)
        {
        }

        public function listAll(string $path, string $key, array $query = [], int $sleepUs = 250000): array
        {
            throw new RuntimeException($this->marker . ' 19121212-1212');
        }
    };
};
$failed = jeab_fortnox_kund_fakturasummor(['action' => 'fortnox.kund_fakturasummor', 'account' => 'Jarfalla']);
$failedJson = json_encode($failed, JSON_UNESCAPED_UNICODE);
ffs(($failed['ok'] ?? true) === false && ($failed['error'] ?? '') === 'Fakturorna gick inte att hämta.', 'Fortnox-fel blir ett generellt fel');
ffs(is_string($failedJson) && !str_contains($failedJson, $leakMarker) && !str_contains($failedJson, '19121212-1212'), 'feltexten läcker inte persondata');

$src = (string) file_get_contents(dirname(__DIR__) . '/src/fortnox_kund_fakturasummor.php');
$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
preg_match_all('/->([A-Za-z0-9_]+)\(/', $src, $methods);
ffs($methods[1] !== [] && array_values(array_unique($methods[1])) === ['listAll'], 'källan anropar bara listAll');
ffs(!str_contains($src, 'OrganisationNumber') && !str_contains($src, 'error_log') && !str_contains($src, 'appLog') && !str_contains($src, 'file_put_contents') && !preg_match('/\b(mail|curl_exec|fwrite)\s*\(/', $src), 'källan läser inte organisationsnummer och skriver inte');
$hookAt = strpos($hook, "=== 'fortnox.kund_fakturasummor'");
$genericAt = strpos($hook, "str_starts_with((string) (\$input['action'] ?? ''), 'fortnox.')");
ffs($hookAt !== false && $genericAt !== false && $hookAt < $genericAt && str_contains($hook, 'jeab_inkorg_hook_authorized()') && str_contains($hook, 'jeab_fortnox_kund_fakturasummor('), 'hook.php tar kroken med inkorgsgrinden före fortnox.*');

unset($GLOBALS['_JEAB_FORTNOX_KUND_FAKTURASUMMOR_FACTORY']);
$GLOBALS['_JEAB_FORTNOX_GET_ONLY'] = true;
$pages = [];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path) use (&$pages, $secretMarker): array {
    $pages[] = $method . ' ' . $path;
    if ($method !== 'GET') {
        throw new RuntimeException('write');
    }
    if (str_starts_with($path, '/customers')) {
        if (str_contains($path, 'page=1')) {
            return ['MetaInformation' => ['@TotalPages' => 2], 'Customers' => [[
                'CustomerNumber' => '1',
                'Name' => 'A ' . $secretMarker,
                'Address1' => 'A-gatan 1',
                'ZipCode' => '11122',
                'City' => 'Järfälla',
                'Email' => 'a@example.com',
                'Phone1' => '08-100000',
                'Phone2' => '0701000000',
            ]]];
        }
        return ['MetaInformation' => ['@TotalPages' => 2], 'Customers' => [[
            'CustomerNumber' => '2',
            'Name' => 'B',
            'Address1' => 'B-gatan 2',
            'ZipCode' => '22233',
            'City' => 'Malmö',
            'Email' => 'b@example.com',
            'Phone1' => '0702000000',
            'Phone2' => '',
        ]]];
    }
    if (str_contains($path, 'page=1')) {
        return ['MetaInformation' => ['@TotalPages' => 2], 'Invoices' => [[
            'CustomerNumber' => '1',
            'InvoiceDate' => '2024-01-01',
            'Total' => 1000,
            'TaxReduction' => 100,
            'TotalToPay' => 900,
            'Cancelled' => false,
            'Credit' => false,
        ]]];
    }
    return ['MetaInformation' => ['@TotalPages' => 2], 'Invoices' => [
        [
            'CustomerNumber' => '1',
            'InvoiceDate' => '2024-02-01',
            'Total' => 200,
            'TaxReduction' => 0,
            'TotalToPay' => 200,
            'Cancelled' => false,
            'Credit' => true,
        ],
        [
            'CustomerNumber' => '2',
            'InvoiceDate' => '2024-03-01',
            'Total' => 3000,
            'TaxReduction' => 0,
            'TotalToPay' => 3000,
            'Cancelled' => false,
            'Credit' => false,
        ],
        [
            'CustomerNumber' => '2',
            'InvoiceDate' => '2018-01-01',
            'Total' => 9999,
            'TotalToPay' => 9999,
            'Cancelled' => false,
            'Credit' => false,
        ],
    ]];
};
$auth(true);
$paged = jeab_fortnox_kund_fakturasummor([
    'action' => 'fortnox.kund_fakturasummor',
    'account' => 'Jarfalla',
    'from_date' => '2019-10-15',
    'to_date' => '2026-10-15',
]);
$pagedRows = $byNumber($paged['rows'] ?? []);
ffs(count(array_filter($pages, static fn (string $call): bool => str_contains($call, 'page=1'))) === 2 && count(array_filter($pages, static fn (string $call): bool => str_contains($call, 'page=2'))) === 2, 'listAll hämtar båda sidorna');
ffs(count(array_filter($pages, static fn (string $call): bool => str_contains($call, 'limit=500'))) === 4, 'varje sida ber om limit 500');
ffs(count(array_filter($pages, static fn (string $call): bool => !str_starts_with($call, 'GET '))) === 0, 'sidhämtningen är bara GET');
ffs(($pagedRows['1']['sum_to_pay'] ?? 0) === 700.0 && ($pagedRows['1']['sum_total_inkl_moms'] ?? 0) === 800.0 && ($pagedRows['1']['namn'] ?? '') === 'A' && ($pagedRows['2']['sum_to_pay'] ?? 0) === 3000.0, 'sidor slås ihop och kredit dras av');
$pagedJson = json_encode($paged, JSON_UNESCAPED_UNICODE);
ffs(is_string($pagedJson) && !str_contains($pagedJson, $secretMarker), 'sidhämtningen släpper inte personnummer');
unset($GLOBALS['_JEAB_FORTNOX_REQUEST'], $GLOBALS['_JEAB_FORTNOX_GET_ONLY']);

$runner = $tmp . '/hook_runner.php';
$statusFile = $tmp . '/hook_status.txt';
file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$tmp = getenv('JEAB_FFS_DATA');
$root = getenv('JEAB_FFS_ROOT');
$token = getenv('JEAB_FFS_TOKEN');
$body = getenv('JEAB_FFS_BODY');
$auth = getenv('JEAB_FFS_AUTH');
$statusFile = getenv('JEAB_FFS_STATUS');
if (!is_string($tmp) || !is_string($root) || !is_string($token) || !is_string($body) || !is_string($statusFile)) {
    fwrite(STDERR, "missing env\n");
    exit(2);
}
$GLOBALS['JEAB_NO_SESSION'] = true;
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
putenv('JEAB_DISABLE_SQLITE=1');
require_once $root . '/src/fortnox.php';
$GLOBALS['_JEAB_FORTNOX_KUND_FAKTURASUMMOR_FACTORY'] = static function (string $account): FortnoxAPI {
    return new class($account) extends FortnoxAPI {
        public function __construct(private string $accountName)
        {
        }

        public function listAll(string $path, string $key, array $query = [], int $sleepUs = 250000): array
        {
            if ($path === '/invoices' && $key === 'Invoices') {
                return [[
                    'CustomerNumber' => '10',
                    'InvoiceDate' => '2024-01-15',
                    'Total' => 1000,
                    'TaxReduction' => 100,
                    'TotalToPay' => 900,
                    'Cancelled' => false,
                    'Credit' => false,
                ]];
            }
            if ($path === '/customers' && $key === 'Customers') {
                return [[
                    'CustomerNumber' => '10',
                    'Name' => 'Test 19121212-1212 Kund',
                    'Address1' => 'Testgatan 1',
                    'ZipCode' => '11122',
                    'City' => 'Järfälla',
                    'Email' => 'test@example.com',
                    'Phone1' => '08-1112233',
                    'Phone2' => '+46701112233',
                ]];
            }
            throw new RuntimeException('fel endpoint');
        }
    };
};
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';
if ($auth === '1') {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
} elseif ($auth === '2') {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-the-inbox-token';
}
$GLOBALS['_JEAB_RAW_BODY'] = $body;
register_shutdown_function(static function () use ($statusFile): void {
    file_put_contents($statusFile, (string) http_response_code());
});
require $root . '/hook.php';
PHP);

$runHook = static function (string $body, string $authMode) use ($runner, $tmp, $inboxToken, $statusFile): array {
    @unlink($statusFile);
    $cmd = 'JEAB_DISABLE_SQLITE=1'
        . ' JEAB_FFS_DATA=' . escapeshellarg($tmp)
        . ' JEAB_FFS_ROOT=' . escapeshellarg(dirname(__DIR__))
        . ' JEAB_FFS_TOKEN=' . escapeshellarg($inboxToken)
        . ' JEAB_FFS_BODY=' . escapeshellarg($body)
        . ' JEAB_FFS_AUTH=' . escapeshellarg($authMode)
        . ' JEAB_FFS_STATUS=' . escapeshellarg($statusFile)
        . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    ffs(is_resource($proc), 'hook-processen startade');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $text = is_string($stdout) ? $stdout : '';
    ffs(!str_contains($text, $inboxToken) && !str_contains((string) $stderr, '19121212-1212'), 'hook-svaret läcker varken token eller personnummer');
    $json = json_decode($text, true);
    $status = is_file($statusFile) ? (int) file_get_contents($statusFile) : 0;
    return ['exit' => $code, 'status' => $status, 'json' => is_array($json) ? $json : [], 'body' => $text, 'stderr' => is_string($stderr) ? $stderr : ''];
};

$httpDeny = $runHook(json_encode(['action' => 'fortnox.kund_fakturasummor', 'account' => 'Jarfalla']), '0');
ffs($httpDeny['exit'] === 0 && $httpDeny['status'] === 401 && ($httpDeny['json']['ok'] ?? true) === false && ($httpDeny['json']['error'] ?? '') === 'Fel token.', 'hook.php utan token avvisar');

$httpIncoming = $runHook(json_encode(['action' => 'fortnox.kund_fakturasummor', 'account' => 'Jarfalla']), '2');
ffs($httpIncoming['status'] === 401 && ($httpIncoming['json']['error'] ?? '') === 'Fel token.', 'hook.php avvisar den vanliga webhook-token');

$httpBad = $runHook(json_encode(['action' => 'fortnox.kund_fakturasummor', 'account' => 'Stockholm']), '1');
ffs($httpBad['status'] === 400 && ($httpBad['json']['error'] ?? '') === 'Ogiltigt bolag.', 'hook.php avvisar fel bolag');
ffs(!str_contains($httpBad['body'], 'Testgatan'), 'fel bolag lämnar ingen kund');

$httpOk = $runHook(json_encode(['action' => 'fortnox.kund_fakturasummor', 'account' => 'Malmo']), '1');
ffs($httpOk['status'] === 200 && ($httpOk['json']['ok'] ?? false) === true && ($httpOk['json']['read_only'] ?? 0) === 1 && ($httpOk['json']['account'] ?? '') === 'Malmo' && ($httpOk['json']['count'] ?? 0) === 1, 'hook.php lämnar summeringen');
$hookRow = $httpOk['json']['rows'][0] ?? [];
ffs(is_array($hookRow) && array_keys($hookRow) === $allowed && ($hookRow['namn'] ?? '') === 'Test Kund' && ($hookRow['mobil'] ?? '') === '+46701112233' && ($hookRow['sum_to_pay'] ?? 0) === 900, 'hook.php vitlistar, tar bort personnumret och väljer mobil');
ffs(!str_contains($httpOk['body'], '19121212-1212') && !str_contains($httpOk['body'], 'OrganisationNumber'), 'hook.php släpper inte personnummer');

echo "Alla OK\n";
