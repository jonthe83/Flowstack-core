<?php

declare(strict_types=1);

/** inbox.fortnox_kunder: vitlistad kundlista, mockad FortnoxAPI, inga skrivningar. */

$tmp = sys_get_temp_dir() . '/jeab-fk-' . bin2hex(random_bytes(4));
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
require_once dirname(__DIR__) . '/src/inkorg_fortnox_kunder.php';

function fk(bool $ok, string $message): void
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

$allowed = ['CustomerNumber', 'Name', 'Email', 'Phone1', 'Phone2', 'Address1', 'ZipCode', 'City', 'bolag'];
$secretMarker = 'ORG-SHOULD-DROP';
$leakMarker = 'LEAK-MARKER-ORG';

$bag = new stdClass();
$bag->calls = [];
$install = static function (array $rows) use (&$bag): void {
    $bag = new stdClass();
    $bag->calls = [];
    $GLOBALS['_JEAB_INKORG_FORTNOX_KUNDER_FACTORY'] = static function (string $account) use ($rows, $bag): FortnoxAPI {
        return new class($account, $rows, $bag) extends FortnoxAPI {
            /** @param list<array<string, mixed>> $rows */
            public function __construct(private string $accountName, private array $rows, private stdClass $bag)
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
                if ($path !== '/customers' || $key !== 'Customers') {
                    throw new RuntimeException('fel endpoint');
                }
                return $this->rows;
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

$sample = [
    [
        'CustomerNumber' => '9001',
        'Name' => 'Testkund',
        'Email' => 'test@example.com',
        'Phone1' => '0700000000',
        'Phone2' => '080000000',
        'Address1' => 'Testgatan 1',
        'ZipCode' => '11122',
        'City' => 'Sverige',
        'OrganisationNumber' => $secretMarker,
        'Comments' => 'not-a-customer-field',
        'Country' => 'SE',
    ],
    [
        'CustomerNumber' => 9002,
        'Name' => 'Andra testkunden',
        'Email' => 'andra@example.com',
        'Phone1' => '',
        'Phone2' => null,
        'Address1' => 'Exempelvägen 2',
        'ZipCode' => '22233',
        'City' => ' Sweden ',
        'OrganisationNumber' => $secretMarker,
    ],
    [
        'CustomerNumber' => '9003',
        'Name' => 'Tredje testkunden',
        'Email' => '',
        'Phone1' => '',
        'Phone2' => '',
        'Address1' => '',
        'ZipCode' => '',
        'City' => 'Malmö',
    ],
    'skip-me',
];

$auth(true);
$install($sample);
$ok = jeab_inkorg_fortnox_kunder(['action' => 'inbox.fortnox_kunder', 'account' => 'Jarfalla']);
fk(($ok['ok'] ?? false) === true, 'listan svarar ok');
fk(($ok['account'] ?? '') === 'Jarfalla', 'account är Jarfalla');
fk(($ok['count'] ?? -1) === 3, 'count är tre kunder');
fk(count($bag->calls) === 1 && $bag->calls[0]['path'] === '/customers' && $bag->calls[0]['key'] === 'Customers' && $bag->calls[0]['account'] === 'Jarfalla' && $bag->calls[0]['query'] === [] && $bag->calls[0]['argc'] === 2, 'bara listAll /customers Customers');
$encoded = json_encode($ok, JSON_UNESCAPED_UNICODE);
fk(is_string($encoded) && !str_contains($encoded, 'OrganisationNumber') && !str_contains($encoded, $secretMarker), 'OrganisationNumber finns inte i svaret');
fk(!str_contains((string) $encoded, 'not-a-customer-field') && !str_contains((string) $encoded, 'Comments'), 'övriga fält är borta');
foreach ($ok['customers'] as $i => $customer) {
    fk(array_keys($customer) === $allowed, 'kund ' . $i . ' har bara vitlistade nycklar');
    fk(($customer['bolag'] ?? '') === 'Jarfalla', 'kund ' . $i . ' har bolag Jarfalla');
    fk(!array_key_exists('OrganisationNumber', $customer), 'kund ' . $i . ' saknar OrganisationNumber');
}
fk($ok['customers'][0]['City'] === '' && $ok['customers'][0]['CustomerNumber'] === '9001' && $ok['customers'][0]['Email'] === 'test@example.com', 'Sverige tas bort som ort');
fk($ok['customers'][1]['City'] === '' && $ok['customers'][1]['CustomerNumber'] === '9002' && $ok['customers'][1]['Phone2'] === '', 'Sweden tas bort och nummer blir text');
fk($ok['customers'][2]['City'] === 'Malmö', 'en riktig ort lämnas kvar');

$auth(true);
$install($sample);
$malmo = jeab_inkorg_fortnox_kunder(['action' => 'inbox.fortnox_kunder', 'account' => 'Malmo']);
fk(($malmo['ok'] ?? false) === true && ($malmo['account'] ?? '') === 'Malmo' && ($malmo['customers'][0]['bolag'] ?? '') === 'Malmo', 'Malmo sätter bolag');
fk($bag->calls[0]['account'] === 'Malmo', 'klienten skapas för Malmo');

foreach (['', 'jarfalla', 'Stockholm', ' JEAB'] as $bad) {
    $auth(true);
    $install($sample);
    $deniedAccount = jeab_inkorg_fortnox_kunder(['action' => 'inbox.fortnox_kunder', 'account' => $bad]);
    fk(($deniedAccount['ok'] ?? true) === false && ($deniedAccount['error'] ?? '') === 'Ogiltigt bolag.' && ($deniedAccount['status'] ?? 0) === 400, 'ogiltigt bolag avvisas: ' . json_encode($bad));
    fk($bag->calls === [], 'ogiltigt bolag anropar inte Fortnox');
    fk(!isset($deniedAccount['customers']), 'ogiltigt bolag lämnar ingen kundlista');
}

$auth(false);
$install($sample);
$unauth = jeab_inkorg_fortnox_kunder(['action' => 'inbox.fortnox_kunder', 'account' => 'Jarfalla']);
fk(($unauth['ok'] ?? true) === false && ($unauth['error'] ?? '') === 'Fel token.' && ($unauth['status'] ?? 0) === 401, 'anrop utan token avvisas');
fk($bag->calls === [], 'avvisat anrop läser inte kunder');

$auth(true);
$GLOBALS['_JEAB_INKORG_FORTNOX_KUNDER_FACTORY'] = static function (string $account) use ($leakMarker): FortnoxAPI {
    return new class($leakMarker) extends FortnoxAPI {
        public function __construct(private string $marker)
        {
        }

        public function listAll(string $path, string $key, array $query = [], int $sleepUs = 250000): array
        {
            throw new RuntimeException($this->marker . ' OrganisationNumber');
        }
    };
};
$failed = jeab_inkorg_fortnox_kunder(['action' => 'inbox.fortnox_kunder', 'account' => 'Jarfalla']);
$failedJson = json_encode($failed, JSON_UNESCAPED_UNICODE);
fk(($failed['ok'] ?? true) === false && ($failed['error'] ?? '') === 'Kundlistan gick inte att hämta.', 'Fortnox-fel blir ett generellt fel');
fk(is_string($failedJson) && !str_contains($failedJson, $leakMarker) && !str_contains($failedJson, 'OrganisationNumber'), 'feltexten läcker inte kunddata');

$src = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_fortnox_kunder.php');
$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
fk(!str_contains($src, 'OrganisationNumber') && !str_contains($src, 'error_log') && !str_contains($src, 'appLog'), 'källan läser inte organisationsnummer och loggar inte');
fk(str_contains($hook, "=== 'inbox.fortnox_kunder'") && str_contains($hook, 'jeab_inkorg_hook_authorized()') && str_contains($hook, 'jeab_inkorg_fortnox_kunder('), 'hook.php registrerar kroken bakom samma grind');
fk(!str_contains((string) file_get_contents(dirname(__DIR__) . '/src/inkorg_auto.php'), 'fortnox_kunder'), 'inkorg_auto.php är orörd av den här kroken');

$runner = $tmp . '/hook_runner.php';
$statusFile = $tmp . '/hook_status.txt';
file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$tmp = getenv('JEAB_FK_DATA');
$root = getenv('JEAB_FK_ROOT');
$token = getenv('JEAB_FK_TOKEN');
$body = getenv('JEAB_FK_BODY');
$auth = getenv('JEAB_FK_AUTH');
$statusFile = getenv('JEAB_FK_STATUS');
if (!is_string($tmp) || !is_string($root) || !is_string($token) || !is_string($body) || !is_string($statusFile)) {
    fwrite(STDERR, "missing env\n");
    exit(2);
}
$GLOBALS['JEAB_NO_SESSION'] = true;
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
putenv('JEAB_DISABLE_SQLITE=1');
require_once $root . '/src/fortnox.php';
$GLOBALS['_JEAB_INKORG_FORTNOX_KUNDER_FACTORY'] = static function (string $account): FortnoxAPI {
    return new class($account) extends FortnoxAPI {
        public function __construct(private string $accountName)
        {
        }

        public function listAll(string $path, string $key, array $query = [], int $sleepUs = 250000): array
        {
            if ($path !== '/customers' || $key !== 'Customers' || $query !== []) {
                throw new RuntimeException('fel endpoint');
            }
            return [[
                'CustomerNumber' => '9001',
                'Name' => 'Testkund',
                'Email' => 'test@example.com',
                'Phone1' => '0700000000',
                'Phone2' => '',
                'Address1' => 'Testgatan 1',
                'ZipCode' => '11122',
                'City' => 'Sverige',
                'OrganisationNumber' => 'ORG-SHOULD-DROP',
                'Comments' => 'not-a-customer-field',
            ]];
        }
    };
};
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';
if ($auth === '1') {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
}
$GLOBALS['_JEAB_RAW_BODY'] = $body;
register_shutdown_function(static function () use ($statusFile): void {
    file_put_contents($statusFile, (string) http_response_code());
});
require $root . '/hook.php';
PHP);

$runHook = static function (string $body, bool $withToken) use ($runner, $tmp, $inboxToken, $statusFile): array {
    @unlink($statusFile);
    $cmd = 'JEAB_DISABLE_SQLITE=1'
        . ' JEAB_FK_DATA=' . escapeshellarg($tmp)
        . ' JEAB_FK_ROOT=' . escapeshellarg(dirname(__DIR__))
        . ' JEAB_FK_TOKEN=' . escapeshellarg($inboxToken)
        . ' JEAB_FK_BODY=' . escapeshellarg($body)
        . ' JEAB_FK_AUTH=' . ($withToken ? '1' : '0')
        . ' JEAB_FK_STATUS=' . escapeshellarg($statusFile)
        . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fk(is_resource($proc), 'hook-processen startade');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $text = is_string($stdout) ? $stdout : '';
    fk(!str_contains($text, $inboxToken) && !str_contains((string) $stderr, 'ORG-SHOULD-DROP'), 'hook-svaret läcker varken token eller organisationsnummer');
    $json = json_decode($text, true);
    $status = is_file($statusFile) ? (int) file_get_contents($statusFile) : 0;
    return ['exit' => $code, 'status' => $status, 'json' => is_array($json) ? $json : [], 'body' => $text, 'stderr' => is_string($stderr) ? $stderr : ''];
};

$httpDeny = $runHook(json_encode(['action' => 'inbox.fortnox_kunder', 'account' => 'Jarfalla']), false);
fk($httpDeny['exit'] === 0 && $httpDeny['status'] === 401 && ($httpDeny['json']['ok'] ?? true) === false && ($httpDeny['json']['error'] ?? '') === 'Fel token.', 'hook.php utan token avvisar');

$httpBad = $runHook(json_encode(['action' => 'inbox.fortnox_kunder', 'account' => 'Stockholm']), true);
fk($httpBad['status'] === 400 && ($httpBad['json']['ok'] ?? true) === false && ($httpBad['json']['error'] ?? '') === 'Ogiltigt bolag.', 'hook.php avvisar fel bolag');
fk(!str_contains($httpBad['body'], 'Testkund'), 'fel bolag lämnar ingen kund');

$httpOk = $runHook(json_encode(['action' => 'inbox.fortnox_kunder', 'account' => 'Malmo']), true);
fk($httpOk['status'] === 200 && ($httpOk['json']['ok'] ?? false) === true && ($httpOk['json']['account'] ?? '') === 'Malmo' && ($httpOk['json']['count'] ?? 0) === 1, 'hook.php lämnar listan');
$hookCustomer = $httpOk['json']['customers'][0] ?? [];
fk(is_array($hookCustomer) && array_keys($hookCustomer) === $allowed && ($hookCustomer['City'] ?? 'x') === '' && ($hookCustomer['bolag'] ?? '') === 'Malmo', 'hook.php vitlistar och tar bort Sverige');
fk(!str_contains($httpOk['body'], 'ORG-SHOULD-DROP') && !str_contains($httpOk['body'], 'OrganisationNumber'), 'hook.php släpper inte organisationsnummer');

echo "Alla OK\n";
