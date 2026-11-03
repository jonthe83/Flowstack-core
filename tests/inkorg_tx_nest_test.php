<?php

declare(strict_types=1);

/**
 * Delad PDO: inkorgens transaktioner och sidolagrets transaktion
 * får ligga i varandra. Bara det yttersta anropet gör begin/commit.
 * DDL körs inte medan en transaktion är öppen.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

final class JeabTxProbePdo extends PDO
{
    public int $begins = 0;

    public int $commits = 0;

    public int $rollbacks = 0;

    /** @var list<string> */
    public array $ddl = [];

    public function beginTransaction(): bool
    {
        $this->begins++;

        return parent::beginTransaction();
    }

    public function commit(): bool
    {
        if (!$this->inTransaction()) {
            throw new PDOException('There is no active transaction');
        }
        $this->commits++;

        return parent::commit();
    }

    public function rollBack(): bool
    {
        if (!$this->inTransaction()) {
            throw new PDOException('There is no active transaction');
        }
        $this->rollbacks++;

        return parent::rollBack();
    }

    public function exec(string $statement): int|false
    {
        if ($this->inTransaction() && preg_match('/^\s*(CREATE|ALTER|DROP|RENAME|TRUNCATE)\b/i', $statement) === 1) {
            $this->ddl[] = $statement;
        }

        return parent::exec($statement);
    }
}

$tmp = sys_get_temp_dir() . '/jeab-inkorg-tx-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
file_put_contents($tmp . '/inkorg/messages.json', json_encode(['messages' => []], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/inkorg/threads.json', json_encode(['threads' => new stdClass()], JSON_UNESCAPED_UNICODE));

$GLOBALS['_JEAB_PDO_FACTORY'] = static function (string $dsn, ?string $user, ?string $pass, array $opts): PDO {
    return new JeabTxProbePdo($dsn, $user, $pass, $opts);
};

require dirname(__DIR__) . '/src/bootstrap.php';

$fail = 0;
function tx_assert(bool $ok, string $message): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $message . "\n";
    if (!$ok) {
        $fail++;
    }
}

tx_assert(jeab_inkorg_sql_set_storage('sql') && jeab_inkorg_storage_mode() === 'sql', 'sql-läge');
$store = jeab_inkorg_pdo_store();
$pdo = jeab_inkorg_store_pdo();
$side = jeab_inkorg_side_pdo();
tx_assert($store instanceof JeabPdoInkorgStore, 'inkorg-store');
tx_assert($pdo instanceof JeabTxProbePdo && $side === $pdo, 'sidolagret och inkorgen delar PDO');
if (!$pdo instanceof JeabTxProbePdo || !$store instanceof JeabPdoInkorgStore) {
    exit(1);
}

$baseBegins = $pdo->begins;
$baseCommits = $pdo->commits;
$baseRollbacks = $pdo->rollbacks;

$row = $store->updateThread('th-nest', static function (array $cur): array {
    jeab_inkorg_side_quote_save('th-nest', 'under-tråd');
    $cur['status'] = 'closed';
    $cur['tags'] = [];

    return $cur;
});
tx_assert(($row['status'] ?? '') === 'closed', 'tråden sparas när sidolagret anropas inuti');
tx_assert($pdo->begins === $baseBegins + 1, 'ett begin för nästlad tråd och sidolager, fick ' . ($pdo->begins - $baseBegins));
tx_assert($pdo->commits === $baseCommits + 1, 'ett commit för nästlad tråd och sidolager, fick ' . ($pdo->commits - $baseCommits));
tx_assert($pdo->ddl === [], 'ingen DDL inne i den nästlade transaktionen');
tx_assert(!$pdo->inTransaction() && jeab_pdo_tx_depth($pdo) === 0, 'transaktionen är stängd efter commit');
$quote = $pdo->prepare('SELECT payload FROM inkorg_side_doc WHERE name = ?');
$quote->execute(['quote-preview']);
$quoteDoc = json_decode((string) $quote->fetchColumn(), true);
tx_assert(is_array($quoteDoc) && ($quoteDoc['text'] ?? '') === 'under-tråd', 'sidotexten committas med tråden');

$begins = $pdo->begins;
$commits = $pdo->commits;
$rolled = false;
try {
    $store->updateThread('th-nest', static function (array $cur): array {
        jeab_inkorg_side_quote_save('th-nest', 'ska-rullas');
        $cur['status'] = 'open';
        throw new RuntimeException('avbryt');
    });
} catch (RuntimeException $e) {
    $rolled = $e->getMessage() === 'avbryt';
}
tx_assert($rolled, 'inre fel bubblar upp');
tx_assert($pdo->begins === $begins + 1 && $pdo->commits === $commits && $pdo->rollbacks === $baseRollbacks + 1, 'inre fel rullar yttersta transaktionen');
tx_assert(!$pdo->inTransaction() && jeab_pdo_tx_depth($pdo) === 0, 'djupet är noll efter rollback');
$kept = $store->threadsByIds(['th-nest'])['th-nest'] ?? [];
tx_assert(is_array($kept) && ($kept['status'] ?? '') === 'closed', 'trådstatusen ligger kvar efter rollback');
$quote->execute(['quote-preview']);
$quoteDoc = json_decode((string) $quote->fetchColumn(), true);
tx_assert(is_array($quoteDoc) && ($quoteDoc['text'] ?? '') === 'under-tråd', 'sidotexten rullas tillbaka med tråden');

$begins = $pdo->begins;
$commits = $pdo->commits;
jeab_inkorg_side_update('quote-preview', static function (array $doc) use ($store): array {
    $store->updateThread('th-inner', static function (array $cur): array {
        $cur['status'] = 'waiting_customer';
        $cur['tags'] = [];

        return $cur;
    });
    $store->replaceListRows([[
        'id' => 'th-inner',
        'company' => 'jarfalla',
        'customer_email' => 'nest@example.test',
        'updated_at' => '2020-01-02T10:00:00+01:00',
    ]]);
    $doc['text'] = 'yttre';

    return [true, $doc];
});
tx_assert($pdo->begins === $begins + 1 && $pdo->commits === $commits + 1, 'sidolager ytterst och inkorg inuti delar ett begin/commit');
$inner = $store->threadsByIds(['th-inner'])['th-inner'] ?? [];
tx_assert(is_array($inner) && ($inner['status'] ?? '') === 'waiting_customer', 'inre trådskrivning syns efter yttre commit');
$list = $pdo->query("SELECT id FROM inkorg_list WHERE id = 'th-inner'")->fetchColumn();
tx_assert($list === 'th-inner', 'listrader som skrivs inuti sidolagret committas');
tx_assert($pdo->ddl === [], 'fortfarande ingen DDL inne i transaktioner');

$begins = $pdo->begins;
$commits = $pdo->commits;
$seen = jeab_inkorg_thread_write('th-read', static function (array $t): array {
    jeab_inkorg_side_quote_save('th-read', 'under-läst');
    $t['status'] = 'open';
    $t['tags'] = [];
    $t['read'] = ['p1' => '2020-01-03T00:00:00+01:00'];

    return $t;
});
tx_assert(is_array($seen) && (($seen['read']['p1'] ?? '') !== ''), 'inkorg.read-vägen tål sidolager i samma PDO');
tx_assert($pdo->begins === $begins + 1 && $pdo->commits === $commits + 1, 'läst-markering öppnar inte en andra transaktion');
$quote->execute(['quote-preview']);
$quoteDoc = json_decode((string) $quote->fetchColumn(), true);
tx_assert(is_array($quoteDoc) && ($quoteDoc['thread'] ?? '') === 'th-read' && ($quoteDoc['text'] ?? '') === 'under-läst', 'sidotexten från läst-vägen är sparad');

$locked = $store->lockedThreads(static function (?array $doc): array {
    jeab_inkorg_side_correction_add('{"id":"nest"}');
    $threads = is_array($doc['threads'] ?? null) ? $doc['threads'] : [];
    $threads['th-lock'] = ['status' => 'open', 'tags' => [], 'created_at' => '2020-01-04T00:00:00+01:00'];

    return ['ok', ['threads' => $threads]];
});
tx_assert($locked === 'ok', 'låsta trådar tål sidolagrets korrigering');
$corr = (int) $pdo->query('SELECT COUNT(*) FROM inkorg_side_correction')->fetchColumn();
tx_assert($corr === 1, 'korrigeringen committas med trådlåset');
tx_assert($pdo->ddl === [], 'DDL stannade utanför alla inkorgstransaktioner');

$mem = new JeabTxProbePdo('sqlite::memory:');
$mem->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mem->exec('CREATE TABLE tx_probe (id INTEGER PRIMARY KEY)');
$guard = jeab_pdo_transaction($mem, static function (PDO $pdo): int {
    $pdo->rollBack();

    return 7;
});
tx_assert($guard === 7 && $mem->commits === 0 && !$mem->inTransaction(), 'commit körs inte när transaktionen redan är borta');

$closed = new JeabTxProbePdo('sqlite::memory:');
$closed->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$closed->exec('CREATE TABLE tx_probe (id INTEGER PRIMARY KEY)');
$implicit = jeab_pdo_transaction($closed, static function (PDO $pdo): int {
    $pdo->exec('INSERT INTO tx_probe (id) VALUES (1)');
    $pdo->exec('COMMIT');

    return 3;
});
tx_assert($implicit === 3, 'implicit commit ger inte There is no active transaction');
tx_assert(jeab_pdo_tx_depth($closed) === 0, 'djupet släpps efter implicit commit');

$schema = new JeabTxProbePdo('sqlite::memory:');
$schema->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$schema->beginTransaction();
jeab_ensure_inkorg_tables($schema, 'sqlite');
jeab_inkorg_side_ensure($schema);
tx_assert($schema->ddl === [] && $schema->inTransaction(), 'schema skapas inte inne i en öppen transaktion');
$schema->rollBack();
jeab_ensure_inkorg_tables($schema, 'sqlite');
jeab_inkorg_side_ensure($schema);
$threads = (int) $schema->query('SELECT COUNT(*) FROM inkorg_threads')->fetchColumn();
$docs = (int) $schema->query('SELECT COUNT(*) FROM inkorg_side_doc')->fetchColumn();
tx_assert($threads === 0 && $docs === 0 && $schema->ddl === [], 'schema skapas när ingen transaktion är öppen');

exit($fail > 0 ? 1 : 0);
