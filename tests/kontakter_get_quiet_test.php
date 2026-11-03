<?php

declare(strict_types=1);

/**
 * GET på kontakter skriver inte och kör ingen DDL.
 * Upptagen settings-läsning lämnar mysql. Räknarna består.
 * Påhittade rader.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

final class JeabQuietPdo extends PDO
{
    /** @var list<string> */
    public static array $writes = [];

    /** @var list<string> */
    public static array $ddl = [];

    public function __construct(string $dsn)
    {
        parent::__construct($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public static function reset(): void
    {
        self::$writes = [];
        self::$ddl = [];
    }

    public static function note(string $sql): void
    {
        $trim = ltrim($sql);
        if (preg_match('/^(ALTER|CREATE|DROP|TRUNCATE|REINDEX)\b/i', $trim) === 1) {
            self::$ddl[] = $trim;
            self::$writes[] = $trim;

            return;
        }
        if (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\b/i', $trim) === 1) {
            self::$writes[] = $trim;
        }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        self::note($query);

        return parent::prepare($query, $options);
    }

    public function exec(string $statement): int|false
    {
        self::note($statement);

        return parent::exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        self::note($query);
        if ($fetchMode === null) {
            return parent::query($query);
        }

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

$tmp = sys_get_temp_dir() . '/jeab-kontakter-quiet-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$sqlitePath = $tmp . '/app.sqlite';
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = ['p' => 'kontakter'];
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $sqlitePath,
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function quiet_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
quiet_ok($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();
jeab_ensure_contacts_tables($pdo, 'sqlite');
if (function_exists('jeab_purchases_store_ensure')) {
    jeab_purchases_store_ensure($pdo);
}
$now = jeab_now();
$ins = $pdo->prepare('INSERT INTO contacts (id, row_json, street_key, postal_code, owner_email, owner_phone, owner_name_fold, company, city_fold, sort_key, sort_city, list_ready, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$rows = [
    ['kt_quiet_j', 'Jarfagatan 1', 'jarfalla', 0],
    ['kt_quiet_m', 'Malmogatan 2', 'malmo', 1],
    ['kt_quiet_e', 'Tomgatan 3', '', 1],
    ['kt_quiet_o', 'Koppladgatan 4', '', 1],
];
foreach ($rows as [$id, $street, $company, $ready]) {
    $row = [
        'id' => $id,
        'street' => $street,
        'street_key' => jeab_kontakt_fold_text($street),
        'city' => 'Teststad',
        'company' => $company,
        'owners' => [['name' => 'Test ' . $id, 'contact' => true, 'email' => '', 'phone' => '']],
    ];
    $ins->execute([
        $id,
        json_encode($row, JSON_UNESCAPED_UNICODE),
        jeab_kontakt_fold_text($street),
        '',
        '',
        '',
        jeab_kontakt_fold_text('Test ' . $id),
        $company,
        jeab_kontakt_fold_text('Teststad'),
        jeab_kontakt_sort_key($street),
        jeab_kontakt_sort_key('Teststad'),
        $ready,
        $now,
        $now,
    ]);
}
$linked = $store->insertReminder([
    'title' => 'Kopplad Malmö',
    'customer_name' => 'Test kt_quiet_o',
    'company' => 'malmo',
    'due_at' => '2026-10-22 11:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$pdo->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute(['kt_quiet_o', (int) $linked['id']]);
quiet_ok(jeab_settings_put($pdo, 'sqlite', 'data_backend', 'mysql'), 'mysql ligger i settings');
unset($GLOBALS['_JEAB_DATA_BACKEND_STORED']);
quiet_ok(jeab_data_backend() === 'mysql', 'settings mysql läses som mysql');
unset($GLOBALS['_JEAB_DATA_BACKEND_STORED']);
$GLOBALS['_JEAB_SETTINGS_BUSY'] = true;
quiet_ok(jeab_data_backend() === 'mysql', 'upptagen settings-läsning växlar inte till json');
unset($GLOBALS['_JEAB_SETTINGS_BUSY'], $GLOBALS['_JEAB_DATA_BACKEND_STORED']);
quiet_ok(jeab_data_backend() === 'mysql', 'mysql ligger kvar när settings svarar igen');
$idx = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index'")->fetchAll(PDO::FETCH_COLUMN);
quiet_ok(in_array('reminders_list_open', $idx, true), 'påminnelselistan har index på list_id, status, bolag och förfallodag');

$before = [
    'contacts' => (int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn(),
    'reminders' => (int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn(),
    'bookings' => (int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn(),
    'purchases' => (int) $pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn(),
    'ready' => (int) $pdo->query('SELECT list_ready FROM contacts WHERE id = \'kt_quiet_j\'')->fetchColumn(),
];

$spy = new JeabQuietPdo('sqlite:' . $sqlitePath);
$GLOBALS['_JEAB_PDO_POOL']['sqlite:' . $sqlitePath] = $spy;
$store->disconnect();
JeabQuietPdo::reset();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['p' => 'kontakter', 'co' => 'jarfalla'];
$error = null;
$flash = null;
$jarfalla = jeab_kontakter_view($store, '', 'alla', 'jarfalla', 50);
$malmo = jeab_kontakter_view($store, '', 'alla', 'malmo', 50);
$html = require dirname(__DIR__) . '/src/pages/kontakter.php';
quiet_ok(is_string($html) && str_contains($html, 'Jarfagatan 1'), 'GET kontakter renderar listan');
quiet_ok(!str_contains($html, 'Malmogatan 2'), 'Malmö filtreras bort via bolagskolumnen');
quiet_ok(isset($jarfalla['rows']['kt_quiet_j'], $jarfalla['rows']['kt_quiet_e']) && !isset($jarfalla['rows']['kt_quiet_m'], $jarfalla['rows']['kt_quiet_o']), 'Järfälla-räkningen följer bolagskolumnen');
quiet_ok(isset($malmo['rows']['kt_quiet_m'], $malmo['rows']['kt_quiet_e'], $malmo['rows']['kt_quiet_o']) && !isset($malmo['rows']['kt_quiet_j']), 'Malmö-räkningen följer bolagskolumnen');
quiet_ok(JeabQuietPdo::$writes === [], 'GET kontakter gör 0 skrivningar, fick ' . count(JeabQuietPdo::$writes));
quiet_ok(JeabQuietPdo::$ddl === [], 'GET kontakter gör 0 DDL, fick ' . count(JeabQuietPdo::$ddl));

$afterPdo = $store->pdo();
$after = [
    'contacts' => (int) $afterPdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn(),
    'reminders' => (int) $afterPdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn(),
    'bookings' => (int) $afterPdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn(),
    'purchases' => (int) $afterPdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn(),
    'ready' => (int) $afterPdo->query('SELECT list_ready FROM contacts WHERE id = \'kt_quiet_j\'')->fetchColumn(),
];
quiet_ok($after === $before, 'antal kontakter, ordrar, bokningar och påminnelser är oförändrat');
quiet_ok($jarfalla['counts']['alla'] === $jarfalla['counts']['adress'] + $jarfalla['counts']['saknas'], 'chipsen summerar');

echo "kontakter get quiet ok\n";
