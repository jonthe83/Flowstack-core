<?php

declare(strict_types=1);

/**
 * Inkorgens lager: 2006 på första anropet byter anslutning och kör samma sats en gång.
 * Samma id (message-id-hash / tråd-id) skrivs inte som en andra rad.
 * Andra försöket som också faller loggas i fellogg, utan mejladress.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-inkorg-gone-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$logDir = $tmp . '/fellogg';
if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
    fwrite(STDERR, "FAIL logg\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $logDir;
$GLOBALS['_JEAB_PDO_RETRY_US'] = 0;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require $root . '/src/bootstrap.php';

$fail = 0;
function ig(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

function ig_err(int $code, string $secret): PDOException
{
    $text = $code === 2013
        ? 'SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server during query'
        : 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away';
    $e = new PDOException($text . ' ' . $secret, $code);
    $e->errorInfo = ['HY000', $code, $code === 2013 ? 'Lost connection to MySQL server during query' : 'MySQL server has gone away'];

    return $e;
}

/** Första prepare mot inkorgen kastar 2006. PDO-hjälparens omslag används inte. */
final class InkorgGonePdo extends PDO
{
    public static int $attempts = 0;

    public static int $failUntil = 0;

    public static int $code = 2006;

    public static string $needle = 'inkorg_';

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (self::$failUntil > 0 && self::$attempts < self::$failUntil && str_contains($query, self::$needle)) {
            self::$attempts++;
            $code = self::$code === 2013 ? 2013 : 2006;
            $text = $code === 2013
                ? 'SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server during query secret.person@example.com'
                : 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away secret.person@example.com';
            $e = new PDOException($text, $code);
            $e->errorInfo = ['HY000', $code, $code === 2013 ? 'Lost connection to MySQL server during query' : 'MySQL server has gone away'];
            throw $e;
        }

        return parent::prepare($query, $options);
    }
}

/** getAttribute säger mysql så adopt kan sätta utf8mb4 utan en riktig server. */
final class InkorgNamesPdo extends PDO
{
    /** @var list<string> */
    public array $sql = [];

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === PDO::ATTR_DRIVER_NAME) {
            return 'mysql';
        }

        return parent::getAttribute($attribute);
    }

    public function exec(string $statement): int|false
    {
        $this->sql[] = $statement;
        if (stripos($statement, 'SET NAMES') === 0) {
            return 0;
        }

        return parent::exec($statement);
    }
}

$secret = 'secret.person@example.com';
$mid = substr(hash('sha256', '<gone-once@example.test>'), 0, 16);
$tid = 'g-' . substr(hash('sha256', 'conv-gone'), 0, 16);
$msg = [
    'id' => $mid,
    'message_id' => '<gone-once@example.test>',
    'thread_id' => $tid,
    'from' => 'kund@example.test',
    'subject' => 'Första',
    'received_at' => '2026-10-23T11:02:00+02:00',
    'body' => 'hej',
];
$thread = [
    'status' => 'open',
    'created_at' => '2026-10-23T11:02:00+02:00',
    'updated_at' => '2026-10-23T11:02:00+02:00',
];

$store = new JeabPdoInkorgStore(jeab_pdo_connect_sqlite());
$GLOBALS['_JEAB_INKORG_STORE'] = $store;
$store->upsertMessage($mid, $msg);
$store->upsertThread($tid, $thread);
$live = jeab_pdo_connect_sqlite();
$before = (int) $live->query('SELECT COUNT(*) FROM inkorg_messages')->fetchColumn();
$threadsBefore = (int) $live->query('SELECT COUNT(*) FROM inkorg_threads')->fetchColumn();
ig($before === 1 && $threadsBefore === 1, 'ett mejl och en tråd ligger i lagret');

$GLOBALS['_JEAB_PDO_EXEC_FAIL'] = ig_err(2006, $secret);
$hit = $store->messagesByIds([$mid]);
ig(isset($hit[$mid]) && ($hit[$mid]['subject'] ?? '') === 'Första', '2006 på första läsningen ger samma rad efter omförsök');
$live = jeab_pdo_connect_sqlite();
ig((int) $live->query('SELECT COUNT(*) FROM inkorg_messages')->fetchColumn() === 1, 'läs-omförsöket skapar ingen rad');

InkorgGonePdo::$attempts = 0;
InkorgGonePdo::$failUntil = 1;
InkorgGonePdo::$needle = 'inkorg_messages';
$gone = new InkorgGonePdo('sqlite:' . $tmp . '/app.sqlite');
$store->adoptPdo($gone);
$again = $store->messagesByIds([$mid]);
$live = jeab_pdo_connect_sqlite();
ig(
    InkorgGonePdo::$attempts === 1 && isset($again[$mid]) && ($again[$mid]['body'] ?? '') === 'hej',
    'första anropet mot lagret faller, samma SELECT körs en gång till, försök ' . InkorgGonePdo::$attempts
);
ig(spl_object_id($store->pdo()) !== spl_object_id($gone), 'inkorgen släpper den döda anslutningen');

InkorgGonePdo::$attempts = 0;
InkorgGonePdo::$failUntil = 1;
InkorgGonePdo::$code = 2013;
$lost = new InkorgGonePdo('sqlite:' . $tmp . '/app.sqlite');
$store->adoptPdo($lost);
$lostHit = $store->messagesByIds([$mid]);
ig(
    InkorgGonePdo::$attempts === 1 && isset($lostHit[$mid]),
    '2013 på första läsningen kör samma SELECT en gång till'
);
InkorgGonePdo::$code = 2006;

$msg['subject'] = 'Andra';
$msg['body'] = 'igen';
InkorgGonePdo::$attempts = 0;
InkorgGonePdo::$failUntil = 1;
$writer = new InkorgGonePdo('sqlite:' . $tmp . '/app.sqlite');
$store->adoptPdo($writer);
$store->upsertMessage($mid, $msg);
$live = jeab_pdo_connect_sqlite();
$subjects = $live->query('SELECT row_json FROM inkorg_messages')->fetchAll(PDO::FETCH_COLUMN);
$decoded = [];
foreach ($subjects as $raw) {
    $row = json_decode((string) $raw, true);
    if (is_array($row)) {
        $decoded[] = (string) ($row['subject'] ?? '');
    }
}
ig(
    count($decoded) === 1 && $decoded[0] === 'Andra' && InkorgGonePdo::$attempts === 1,
    'samma message-id skrivs om en gång och blir inte en andra rad'
);

$thread['status'] = 'closed';
InkorgGonePdo::$attempts = 0;
InkorgGonePdo::$failUntil = 1;
InkorgGonePdo::$needle = 'inkorg_threads';
$threadPdo = new InkorgGonePdo('sqlite:' . $tmp . '/app.sqlite');
$store->adoptPdo($threadPdo);
$store->upsertThread($tid, $thread);
$live = jeab_pdo_connect_sqlite();
$threadCount = (int) $live->query('SELECT COUNT(*) FROM inkorg_threads')->fetchColumn();
$threadStatus = (string) $live->query('SELECT status FROM inkorg_threads WHERE id = ' . $live->quote($tid))->fetchColumn();
ig($threadCount === 1 && $threadStatus === 'closed', 'samma tråd-id uppdateras och räknas en gång, status ' . $threadStatus . ' antal ' . $threadCount);

$loggedBefore = (string) @file_get_contents($logDir . '/app-errors.jsonl');
ig(!str_contains($loggedBefore, '2006 inkorg') && !str_contains($loggedBefore, $secret), 'lyckat omförsök loggar inte 2006 inkorg och inte mejladressen');

InkorgGonePdo::$attempts = 0;
InkorgGonePdo::$failUntil = 9;
InkorgGonePdo::$needle = 'inkorg_messages';
$GLOBALS['_JEAB_PDO_POOL'] = [];
$GLOBALS['_JEAB_PDO_FACTORY'] = static function (string $dsn, ?string $user, ?string $pass, array $opts): PDO {
    unset($user, $pass, $opts);

    return new InkorgGonePdo($dsn);
};
$dead = new InkorgGonePdo('sqlite:' . $tmp . '/app.sqlite');
$store->adoptPdo($dead);
$second = '';
try {
    $store->messagesByIds([$mid]);
} catch (PDOException $e) {
    $second = (string) ($e->errorInfo[1] ?? '');
}
$logged = (string) @file_get_contents($logDir . '/app-errors.jsonl');
$live = jeab_pdo_connect_sqlite();
$still = (int) $live->query('SELECT COUNT(*) FROM inkorg_messages')->fetchColumn();
ig($second === '2006' && InkorgGonePdo::$attempts === 2, 'andra försöket kastas, anrop ' . InkorgGonePdo::$attempts . ' kod ' . $second);
ig(str_contains($logged, '2006 inkorg') && !str_contains($logged, $secret), 'kvarstående 2006 loggas i fellogg utan mejladress');
ig($still === 1, 'misslyckat omförsök lämnar en rad');

unset($GLOBALS['_JEAB_PDO_FACTORY']);
$names = new InkorgNamesPdo('sqlite:' . $tmp . '/app.sqlite');
$charset = new JeabPdoInkorgStore(null);
$charset->adoptPdo($names);
ig(
    in_array(jeab_pdo_mysql_names_sql(), $names->sql, true),
    'ny anslutning sätter utf8mb4'
);

$plain = 0;
try {
    $store->pdo()->exec('INSERT INTO inkorg_missing (n) VALUES (1)');
} catch (PDOException) {
    $plain = 1;
}
ig($plain === 1, 'annat SQL-fel är inte 2006');

exit($fail > 0 ? 1 : 0);
