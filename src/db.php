<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
function jeab_sql_hit(float $seconds, string $sql = ''): void
{
    $GLOBALS['_JEAB_SQL_N'] = (int) ($GLOBALS['_JEAB_SQL_N'] ?? 0) + 1;
    $GLOBALS['_JEAB_SQL_S'] = (float) ($GLOBALS['_JEAB_SQL_S'] ?? 0) + max(0, $seconds);
    if ($sql !== '') {
        $log = $GLOBALS['_JEAB_SQL_LOG'] ?? [];
        if (!is_array($log)) {
            $log = [];
        }
        if (count($log) < 80) {
            $log[] = $sql;
        }
        $GLOBALS['_JEAB_SQL_LOG'] = $log;
    }
    if (function_exists('jeab_timing_query')) {
        jeab_timing_query($sql, $seconds);
    }
    $GLOBALS['_JEAB_PDO_LAST_USED'] = microtime(true);
}

function jeab_sql_stats_reset(): void
{
    $GLOBALS['_JEAB_SQL_N'] = 0;
    $GLOBALS['_JEAB_SQL_S'] = 0.0;
    $GLOBALS['_JEAB_SQL_LOG'] = [];
}

/** @return array{n:int,seconds:float,log:list<string>} */
function jeab_sql_stats(): array
{
    $log = $GLOBALS['_JEAB_SQL_LOG'] ?? [];

    return [
        'n' => (int) ($GLOBALS['_JEAB_SQL_N'] ?? 0),
        'seconds' => (float) ($GLOBALS['_JEAB_SQL_S'] ?? 0),
        'log' => is_array($log) ? array_values(array_map('strval', $log)) : [],
    ];
}

class JeabTraceStatement extends PDOStatement
{
    private ?PDOStatement $retryStmt = null;

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        $owner = jeab_pdo_stmt_owner($this);
        if (!$owner instanceof PDO) {
            return jeab_pdo_call(function () use ($params): bool {
                $t = microtime(true);
                try {
                    return parent::execute($params);
                } finally {
                    jeab_sql_hit(microtime(true) - $t, (string) $this->queryString);
                }
            });
        }

        return jeab_pdo_gone_retry($owner, 'statement', function () use ($params): bool {
            jeab_pdo_fail_once_throw();

            return jeab_pdo_call(function () use ($params): bool {
                $t = microtime(true);
                try {
                    return parent::execute($params);
                } finally {
                    jeab_sql_hit(microtime(true) - $t, (string) $this->queryString);
                }
            });
        }, function (PDO $fresh) use ($params): bool {
            $stmt = $fresh->prepare((string) $this->queryString);
            if (!$stmt instanceof PDOStatement) {
                $gone = new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', 2006);
                $gone->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
                throw $gone;
            }
            $ok = $stmt->execute($params);
            $this->retryStmt = $stmt;

            return $ok;
        }, (string) $this->queryString);
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if ($this->retryStmt instanceof PDOStatement) {
            return $this->retryStmt->fetch($mode, $cursorOrientation, $cursorOffset);
        }

        return parent::fetch($mode, $cursorOrientation, $cursorOffset);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($this->retryStmt instanceof PDOStatement) {
            return $this->retryStmt->fetchAll($mode, ...$args);
        }

        return parent::fetchAll($mode, ...$args);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if ($this->retryStmt instanceof PDOStatement) {
            return $this->retryStmt->fetchColumn($column);
        }

        return parent::fetchColumn($column);
    }

    public function rowCount(): int
    {
        if ($this->retryStmt instanceof PDOStatement) {
            return $this->retryStmt->rowCount();
        }

        return parent::rowCount();
    }
}

class JeabTracePdo extends PDO
{
    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [JeabTraceStatement::class]);
    }

    public function exec(string $statement): int|false
    {
        if (!empty($GLOBALS['_JEAB_PDO_GONE_BREAK'])) {
            return parent::exec($statement);
        }

        return jeab_pdo_gone_retry($this, 'exec', function () use ($statement): int|false {
            jeab_pdo_fail_once_throw();

            return jeab_pdo_call(function () use ($statement): int|false {
                $t = microtime(true);
                try {
                    return parent::exec($statement);
                } finally {
                    jeab_sql_hit(microtime(true) - $t, $statement);
                }
            });
        }, function (PDO $fresh) use ($statement): int|false {
            return $fresh->exec($statement);
        }, $statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return jeab_pdo_gone_retry($this, 'query', function () use ($query, $fetchMode, $fetchModeArgs): PDOStatement|false {
            jeab_pdo_fail_once_throw();

            return jeab_pdo_call(function () use ($query, $fetchMode, $fetchModeArgs): PDOStatement|false {
                $t = microtime(true);
                try {
                    if ($fetchMode === null) {
                        $stmt = parent::query($query);
                    } else {
                        $stmt = parent::query($query, $fetchMode, ...$fetchModeArgs);
                    }

                    return jeab_pdo_bind_stmt($this, $stmt);
                } finally {
                    jeab_sql_hit(microtime(true) - $t, $query);
                }
            });
        }, function (PDO $fresh) use ($query, $fetchMode, $fetchModeArgs): PDOStatement|false {
            if ($fetchMode === null) {
                return $fresh->query($query);
            }

            return $fresh->query($query, $fetchMode, ...$fetchModeArgs);
        }, $query);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return jeab_pdo_gone_retry($this, 'prepare', function () use ($query, $options): PDOStatement|false {
            jeab_pdo_fail_once_throw();

            return jeab_pdo_call(function () use ($query, $options): PDOStatement|false {
                return jeab_pdo_bind_stmt($this, parent::prepare($query, $options));
            });
        }, function (PDO $fresh) use ($query, $options): PDOStatement|false {
            return $fresh->prepare($query, $options);
        }, $query);
    }
}

class JeabDbBusyException extends RuntimeException
{
}

/**
 * COMMIT skickades eller kan ha nått servern. Enheten får inte köras om.
 * Meddelandet är bara felkod och commit, ingen SQL och ingen kundtext.
 */
class JeabPdoCommitUnknownException extends RuntimeException
{
}

function jeab_pdo_busy_message(): string
{
    return 'Databasen är upptagen. Försök igen om en stund.';
}

/** SQLSTATE 2002: värden tar inte emot fler anslutningar. Försök inte en till. */
function jeab_pdo_is_connect_refused(Throwable $e): bool
{
    $msg = $e->getMessage();

    return str_contains($msg, '2002') || str_contains($msg, '[2002]');
}

/** Timeout, inte connection refused. GET får ett extra försök med samma 1 s-tak. */
function jeab_pdo_is_connect_timeout(Throwable $e): bool
{
    $msg = strtolower($e->getMessage());
    if (str_contains($msg, 'refused')) {
        return false;
    }

    return str_contains($msg, 'timed out') || str_contains($msg, 'timeout');
}

function jeab_pdo_get_may_retry_connect(): bool
{
    if (!empty($GLOBALS['_JEAB_PDO_CONNECT_RETRIED']) || !empty($GLOBALS['_JEAB_TICK'])) {
        return false;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    return $method === 'GET' || $method === 'HEAD';
}

function jeab_pdo_is_max_user_connections(Throwable $e): bool
{
    if (!$e instanceof PDOException) {
        return false;
    }
    $info = $e->errorInfo ?? null;
    if (is_array($info) && (int) ($info[1] ?? 0) === 1203) {
        return true;
    }
    if ((int) $e->getCode() === 1203) {
        return true;
    }
    $msg = $e->getMessage();

    return str_contains($msg, 'max_user_connections') || str_contains($msg, '[1203]');
}

function jeab_pdo_open_count(): int
{
    return (int) ($GLOBALS['_JEAB_PDO_OPENS'] ?? 0);
}

function jeab_pdo_live_count(): int
{
    $n = 0;
    foreach ($GLOBALS['_JEAB_PDO_POOL'] ?? [] as $pdo) {
        if ($pdo instanceof PDO) {
            $n++;
        }
    }

    return $n;
}

/**
 * Taket är @@max_user_connections när det är läst (25 på den delade värden).
 * 10 är bara reserv innan första lyckade läsningen. Värdet cachas i
 * data/.db-slots/conn-sample.json och gäller tills nästa läsning.
 */
function jeab_pdo_host_limit(): int
{
    $override = (int) ($GLOBALS['_JEAB_PDO_HOST_LIMIT'] ?? 0);
    if ($override > 0) {
        return $override;
    }
    $live = (int) ($GLOBALS['_JEAB_PDO_HOST_LIMIT_LIVE'] ?? 0);
    if ($live > 0) {
        return $live;
    }
    $cached = jeab_pdo_cached_limit();

    return $cached > 0 ? $cached : 10;
}

/** Bakgrund, poll och tick får använda taket minus 3. Resten är till klick. */
function jeab_pdo_bg_budget(): int
{
    return max(1, jeab_pdo_host_limit() - 3);
}

/**
 * Förhämtning, påminnelse-poll, oläst-räknare, tick och cron.
 * Kalendervågen och klick räknas inte: användaren väntar på dem.
 */
function jeab_pdo_is_background(): bool
{
    if (!empty($GLOBALS['_JEAB_PDO_FORCE_INTERACTIVE'])) {
        return false;
    }
    if (array_key_exists('_JEAB_PDO_BACKGROUND', $GLOBALS)) {
        return (bool) $GLOBALS['_JEAB_PDO_BACKGROUND'];
    }
    if (!empty($GLOBALS['_JEAB_TICK'])) {
        return true;
    }
    if (PHP_SAPI !== 'cli' && function_exists('jeab_request_is_prefetch') && jeab_request_is_prefetch()) {
        return true;
    }
    $intent = strtolower(trim((string) ($_GET['intent'] ?? '')));
    if ($intent === 'sync') {
        return true;
    }
    $part = strtolower(trim((string) ($_GET['ix_part'] ?? '')));
    if ($part === 'counts') {
        return true;
    }
    if ($part === 'all' && trim((string) ($_SERVER['HTTP_X_JEAB_INTERACTIVE'] ?? '')) === '') {
        return true;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('#/(?:tick|sms-cron|sms-tick)\\.php$#', $script) === 1) {
        return true;
    }

    return false;
}

function jeab_pdo_bg_guard(): void
{
    if (!jeab_pdo_is_background() || !empty($GLOBALS['_JEAB_PDO_BG_HELD'])) {
        return;
    }
    $own = jeab_pdo_own_sample_read();
    if ($own !== null && $own >= jeab_pdo_bg_budget()) {
        jeab_pdo_bg_deny('own');
    }
    $fh = jeab_pdo_bg_lock_once();
    if ($fh === null) {
        jeab_pdo_bg_deny('semafor');
    }
    $GLOBALS['_JEAB_PDO_BG_LOCK'] = $fh;
    $GLOBALS['_JEAB_PDO_BG_HELD'] = true;
}

/** @return resource|null */
function jeab_pdo_bg_lock_once()
{
    $max = jeab_pdo_bg_budget();
    $dir = jeab_db_slot_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }
    for ($i = 0; $i < $max; $i++) {
        $fh = @fopen($dir . '/bg-' . $i . '.lock', 'c');
        if ($fh === false) {
            continue;
        }
        if (flock($fh, LOCK_EX | LOCK_NB)) {
            return $fh;
        }
        fclose($fh);
    }

    return null;
}

function jeab_pdo_bg_unlock(): void
{
    $fh = $GLOBALS['_JEAB_PDO_BG_LOCK'] ?? null;
    if (is_resource($fh)) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    unset($GLOBALS['_JEAB_PDO_BG_LOCK'], $GLOBALS['_JEAB_PDO_BG_HELD']);
}

function jeab_pdo_bg_deny(string $why): never
{
    unset($why);
    jeab_pdo_bg_note_deny();
    jeab_pdo_busy_fail(new RuntimeException('semafor'));
}

function jeab_pdo_bg_denied_path(): string
{
    return jeab_db_slot_dir() . '/bg-denied.json';
}

function jeab_pdo_bg_note_deny(): void
{
    $now = time();
    $stamps = jeab_pdo_bg_denied_stamps($now);
    $stamps[] = $now;
    $json = json_encode(['at' => $stamps]);
    if (!is_string($json)) {
        return;
    }
    @file_put_contents(jeab_pdo_bg_denied_path(), $json, LOCK_EX);
}

function jeab_pdo_bg_denied_count(): int
{
    return count(jeab_pdo_bg_denied_stamps(time()));
}

/** @return list<int> */
function jeab_pdo_bg_denied_stamps(int $now): array
{
    $raw = @file_get_contents(jeab_pdo_bg_denied_path());
    $data = is_string($raw) ? json_decode($raw, true) : null;
    $rows = is_array($data) ? ($data['at'] ?? []) : [];
    if (!is_array($rows)) {
        return [];
    }
    $fresh = [];
    foreach ($rows as $stamp) {
        $stamp = (int) $stamp;
        if ($stamp > 0 && ($now - $stamp) < 3600 && count($fresh) < 5000) {
            $fresh[] = $stamp;
        }
    }

    return $fresh;
}

/** @return array{page:string,intent:string,job:string,user:string} */
function jeab_pdo_conn_label(): array
{
    $clean = static function (string $raw): string {
        $raw = strtolower(trim($raw));
        $raw = preg_replace('/[^a-z0-9_-]/', '', $raw) ?? '';

        return substr($raw, 0, 40);
    };
    $user = '';
    $id = '';
    if (function_exists('jeab_login_identity')) {
        // Osäker kaka tömmer jeab_code_person. En kvarliggande kodperson blir inte etikett.
        $labeled = jeab_login_identity(false, false)['person'] ?? null;
        if (is_array($labeled)) {
            $id = (string) ($labeled['id'] ?? '');
        }
    }
    if ($id !== '' && function_exists('jeab_people')) {
        try {
            foreach (jeab_people() as $row) {
                if (!is_array($row) || strcasecmp((string) ($row['id'] ?? ''), $id) !== 0) {
                    continue;
                }
                $name = trim((string) ($row['name'] ?? ''));
                if ($name !== '' && !str_contains($name, '@')) {
                    $parts = preg_split('/\s+/u', $name) ?: [];
                    $user = (string) ($parts[0] ?? '');
                }
                break;
            }
        } catch (Throwable) {
            $user = '';
        }
    }
    $user = preg_replace('/[^\p{L}\p{N}_-]/u', '', $user) ?? '';
    if (function_exists('mb_substr')) {
        $user = mb_substr($user, 0, 40, 'UTF-8');
    } else {
        $user = substr($user, 0, 40);
    }

    return [
        'page' => $clean((string) ($_GET['p'] ?? '')),
        'intent' => $clean((string) ($_GET['intent'] ?? '')),
        'job' => $clean((string) ($_GET['job'] ?? '')),
        'user' => $user,
    ];
}

function jeab_pdo_note_connect(PDO $pdo): void
{
    if (!empty($GLOBALS['_JEAB_PDO_SAMPLING'])) {
        return;
    }
    $driver = '';
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    } catch (Throwable) {
        $driver = '';
    }
    if ($driver === 'mysql') {
        // Sidan läser den sparade räkningen. Diagnosen (conn_stats) frågar servern.
        jeab_pdo_sample_remembered($pdo);

        return;
    }
    $label = jeab_pdo_conn_label();
    $GLOBALS['_JEAB_PDO_CONN_STATS'] = [
        'driver' => $driver,
        'max_user_connections' => jeab_pdo_host_limit(),
        'own_connections' => null,
        'page' => $label['page'],
        'intent' => $label['intent'],
        'job' => $label['job'],
        'user' => $label['user'],
    ];
}

/** Senaste conn-sample.json, utan att fråga servern. */
function jeab_pdo_sample_remembered(PDO $pdo): void
{
    $data = jeab_pdo_sample_file();
    $max = is_array($data) ? max(0, (int) ($data['max_user_connections'] ?? 0)) : 0;
    $own = null;
    if (is_array($data) && array_key_exists('own_connections', $data) && $data['own_connections'] !== null) {
        $at = (int) ($data['at'] ?? 0);
        if ($at > 0 && (time() - $at) <= 30) {
            $own = max(0, (int) $data['own_connections']);
        }
    }
    if ($max <= 0) {
        $max = jeab_pdo_cached_limit();
    }
    $label = jeab_pdo_conn_label();
    $GLOBALS['_JEAB_PDO_CONN_STATS'] = [
        'driver' => 'mysql',
        'max_user_connections' => $max,
        'own_connections' => $own,
        'page' => $label['page'],
        'intent' => $label['intent'],
        'job' => $label['job'],
        'user' => $label['user'],
    ];
    if ($max > 0 && (int) ($GLOBALS['_JEAB_PDO_HOST_LIMIT'] ?? 0) <= 0) {
        $GLOBALS['_JEAB_PDO_HOST_LIMIT_LIVE'] = $max;
    }
    unset($pdo);
}

function jeab_pdo_sample_mysql(PDO $pdo): void
{
    if (!empty($GLOBALS['_JEAB_PDO_SAMPLING'])) {
        return;
    }
    $GLOBALS['_JEAB_PDO_SAMPLING'] = true;
    try {
        $max = 0;
        $own = 0;
        $capped = false;
        try {
            $pdo->exec('SET SESSION MAX_EXECUTION_TIME=1000');
            $capped = true;
        } catch (Throwable) {
            $capped = false;
        }
        try {
            $max = (int) $pdo->query('SELECT @@max_user_connections')->fetchColumn();
        } catch (Throwable) {
            $max = 0;
        }
        try {
            $own = jeab_pdo_own_count($pdo);
        } catch (Throwable) {
            $own = 0;
        }
        if ($capped) {
            try {
                $pdo->exec('SET SESSION MAX_EXECUTION_TIME=0');
            } catch (Throwable) {
            }
        }
        $label = jeab_pdo_conn_label();
        $GLOBALS['_JEAB_PDO_CONN_STATS'] = [
            'driver' => 'mysql',
            'max_user_connections' => $max,
            'own_connections' => $own,
            'page' => $label['page'],
            'intent' => $label['intent'],
            'job' => $label['job'],
            'user' => $label['user'],
        ];
        if ($max > 0 && (int) ($GLOBALS['_JEAB_PDO_HOST_LIMIT'] ?? 0) <= 0) {
            $GLOBALS['_JEAB_PDO_HOST_LIMIT_LIVE'] = $max;
        }
        jeab_pdo_sample_persist($max, $own);
    } finally {
        unset($GLOBALS['_JEAB_PDO_SAMPLING']);
    }
}

/**
 * CURRENT_USER() är konto@värd. PROCESSLIST.USER är bara kontot.
 * Jämförelsen tar kontonamnet så vi räknar våra rader, inte serverns.
 */
function jeab_pdo_own_count(PDO $pdo): int
{
    if (function_exists('jeab_schema_probe_allowed') && !jeab_schema_probe_allowed()) {
        return 0;
    }
    $sql = 'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE USER = SUBSTRING_INDEX(CURRENT_USER(), \'@\', 1)';
    $stmt = $pdo->query($sql);

    return $stmt instanceof PDOStatement ? (int) $stmt->fetchColumn() : 0;
}

function jeab_pdo_sample_persist(int $max, int $own): void
{
    $dir = jeab_db_slot_dir();
    if (!is_dir($dir)) {
        return;
    }
    if ($max <= 0) {
        $max = jeab_pdo_cached_limit();
    }
    $json = json_encode([
        'max_user_connections' => $max,
        'own_connections' => $own,
        'at' => time(),
    ]);
    if (!is_string($json)) {
        return;
    }
    @file_put_contents($dir . '/conn-sample.json', $json, LOCK_EX);
}

/** @return array<string, mixed>|null */
function jeab_pdo_sample_file(): ?array
{
    $path = jeab_db_slot_dir() . '/conn-sample.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($data) ? $data : null;
}

function jeab_pdo_cached_limit(): int
{
    $data = jeab_pdo_sample_file();
    if (!is_array($data)) {
        return 0;
    }

    return max(0, (int) ($data['max_user_connections'] ?? 0));
}

/** Senaste räkningen av våra rader. Äldre än 30 s, eller en gammal serverglobal siffra, används inte. */
function jeab_pdo_own_sample_read(): ?int
{
    $data = jeab_pdo_sample_file();
    if (!is_array($data) || !array_key_exists('own_connections', $data)) {
        return null;
    }
    $at = (int) ($data['at'] ?? 0);
    if ($at <= 0 || (time() - $at) > 30) {
        return null;
    }

    return max(0, (int) $data['own_connections']);
}

function jeab_pdo_conn_log_line(): string
{
    $stats = $GLOBALS['_JEAB_PDO_CONN_STATS'] ?? null;
    $stats = is_array($stats) ? $stats : [];
    $label = jeab_pdo_conn_label();
    $own = $stats['own_connections'] ?? null;
    $max = (int) ($stats['max_user_connections'] ?? jeab_pdo_host_limit());
    $ownText = $own === null ? '-' : (string) (int) $own;
    $page = (string) ($stats['page'] ?? $label['page']);
    $intent = (string) ($stats['intent'] ?? $label['intent']);
    $job = (string) ($stats['job'] ?? $label['job']);
    $user = (string) ($stats['user'] ?? $label['user']);
    if ($page === '') {
        $page = $label['page'];
    }
    if ($intent === '') {
        $intent = $label['intent'];
    }
    if ($job === '') {
        $job = $label['job'];
    }
    if ($user === '') {
        $user = $label['user'];
    }

    return '1203 own=' . $ownText
        . ' max=' . $max
        . ' page=' . ($page !== '' ? $page : '-')
        . ' intent=' . ($intent !== '' ? $intent : '-')
        . ' job=' . ($job !== '' ? $job : '-')
        . ' user=' . ($user !== '' ? $user : '-');
}

function jeab_pdo_log_1203(): void
{
    if (!empty($GLOBALS['_JEAB_PDO_1203_LOGGED'])) {
        return;
    }
    $GLOBALS['_JEAB_PDO_1203_LOGGED'] = true;
    if (jeab_pdo_is_background()) {
        return;
    }
    if (!function_exists('jeab_log_app_error')) {
        return;
    }
    jeab_log_app_error('db-conn', jeab_pdo_conn_log_line());
}

function jeab_pdo_busy_fail(Throwable $e): never
{
    jeab_pdo_log_1203();
    throw new JeabDbBusyException(jeab_pdo_busy_message(), 0, $e);
}

function jeab_pdo_busy_response(Throwable $e): never
{
    unset($e);
    jeab_pdo_log_1203();
    $bg = jeab_pdo_is_background();
    $public = jeab_pdo_busy_message();
    if (!headers_sent()) {
        http_response_code(503);
        if ($bg) {
            header('Retry-After: 3');
        }
    }
    $json = $bg || (function_exists('jeab_wants_json') && jeab_wants_json());
    if ($json) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        $payload = ['ok' => false, 'error' => $public];
        if ($bg) {
            $payload['busy'] = 1;
            $payload['retry'] = 1;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><title>JEAB</title><p>'
        . htmlspecialchars($public, ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

/** @return list<array{source:string,command:string,count:int}> */
function jeab_pdo_process_sources(PDO $pdo): array
{
    if (function_exists('jeab_schema_probe_allowed') && !jeab_schema_probe_allowed()) {
        return [];
    }
    try {
        $stmt = $pdo->query('SELECT COMMAND, HOST FROM information_schema.PROCESSLIST WHERE USER = SUBSTRING_INDEX(CURRENT_USER(), \'@\', 1)');
        $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable) {
        return [];
    }
    $counts = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $cmd = strtolower(trim((string) ($row['COMMAND'] ?? $row['Command'] ?? '')));
        $cmd = preg_replace('/[^a-z]/', '', $cmd) ?? '';
        if ($cmd === '') {
            $cmd = 'other';
        }
        $host = (string) ($row['HOST'] ?? $row['Host'] ?? '');
        $host = explode(':', $host, 2)[0];
        $host = preg_replace('/[^0-9a-zA-Z.:_-]/', '', $host) ?? '';
        if ($host === '') {
            $host = 'local';
        }
        $key = $host . '|' . $cmd;
        if (!isset($counts[$key])) {
            $counts[$key] = ['source' => $host, 'command' => $cmd, 'count' => 0];
        }
        $counts[$key]['count']++;
    }

    return array_values($counts);
}

/** @return array<string, mixed> */
function jeab_pdo_conn_stats(?PDO $pdo = null): array
{
    if (!$pdo instanceof PDO && function_exists('jeab_store')) {
        try {
            $store = jeab_store();
            if ($store instanceof JeabPdoStore) {
                $pdo = $store->pdo();
            }
        } catch (Throwable) {
            $pdo = null;
        }
    }
    $driver = '';
    if ($pdo instanceof PDO) {
        try {
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable) {
            $driver = '';
        }
    }
    $own = null;
    $resolved = false;
    if ($pdo instanceof PDO && $driver === 'mysql') {
        jeab_pdo_sample_mysql($pdo);
        $stats = $GLOBALS['_JEAB_PDO_CONN_STATS'] ?? [];
        if (is_array($stats) && array_key_exists('own_connections', $stats) && $stats['own_connections'] !== null) {
            $own = (int) $stats['own_connections'];
        }
        $outMax = (int) (is_array($stats) ? ($stats['max_user_connections'] ?? 0) : 0);
    } else {
        $outMax = 0;
    }
    if ($pdo instanceof PDO && $driver !== '') {
        $resolved = jeab_settings_value($pdo, $driver, 'db_busy_resolved_v1') === '1';
    }
    $ceiling = $outMax > 0 ? $outMax : jeab_pdo_host_limit();
    $out = [
        'ok' => true,
        'read_only' => true,
        'driver' => $driver,
        'max_user_connections' => $ceiling,
        'own_connections' => $own,
        'db_busy_resolved_v1' => $resolved,
        'semaphore' => [
            'own' => $own,
            'ceiling' => $ceiling,
            'budget' => max(1, $ceiling - 3),
            'denied_background_hour' => jeab_pdo_bg_denied_count(),
        ],
        'processlist' => ($pdo instanceof PDO && $driver === 'mysql') ? jeab_pdo_process_sources($pdo) : [],
    ];

    return $out;
}

/** @return array<string, mixed> */
function jeab_pdo_conn_stats_hook(): array
{
    return jeab_pdo_conn_stats();
}

/**
 * Läsning: jobb, flaggor, liststämpel mot SQL-fingerprint och ombyggnader senaste timmen.
 *
 * @return array<string, mixed>
 */
function jeab_sys_health(): array
{
    $pdo = null;
    if (function_exists('jeab_store')) {
        try {
            $store = jeab_store();
            if ($store instanceof JeabPdoStore) {
                $pdo = $store->pdo();
            }
        } catch (Throwable) {
            $pdo = null;
        }
    }
    $driver = $pdo instanceof PDO && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    $flag = static function (string $key) use ($pdo, $driver): ?string {
        if (!$pdo instanceof PDO) {
            return null;
        }

        return jeab_settings_value($pdo, $driver, $key);
    };
    $jobs = ['running' => [], 'locks' => []];
    if ($pdo instanceof PDO) {
        try {
            $stmt = $pdo->query("SELECT job_id, job_type, status, heartbeat_at FROM data_jobs WHERE status IN ('queued', 'running') ORDER BY heartbeat_at DESC LIMIT 50");
            foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $jobs['running'][] = [
                    'job_id' => (string) ($row['job_id'] ?? ''),
                    'job_type' => (string) ($row['job_type'] ?? ''),
                    'status' => (string) ($row['status'] ?? ''),
                    'heartbeat_at' => (string) ($row['heartbeat_at'] ?? ''),
                ];
            }
        } catch (Throwable) {
        }
        try {
            $stmt = $pdo->query('SELECT job_type, job_id, heartbeat_at FROM data_job_locks ORDER BY job_type LIMIT 50');
            foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $jobs['locks'][] = [
                    'job_type' => (string) ($row['job_type'] ?? ''),
                    'job_id' => (string) ($row['job_id'] ?? ''),
                    'heartbeat_at' => (string) ($row['heartbeat_at'] ?? ''),
                ];
            }
        } catch (Throwable) {
        }
    }
    $stamp = '';
    $fingerprint = '';
    if (class_exists('JeabPdoInkorgStore', false) && function_exists('jeab_inkorg_pdo_store')) {
        $box = jeab_inkorg_pdo_store();
        if ($box instanceof JeabPdoInkorgStore) {
            try {
                $stamp = $box->listStamp();
                $fingerprint = $box->listFingerprint();
            } catch (Throwable) {
            }
        }
    }
    $rebuilds = 0;
    $cut = time() - 3600;
    foreach (explode(',', (string) $flag('inkorg_list_rebuilds')) as $part) {
        $t = (int) $part;
        if ($part !== '' && $t >= $cut) {
            $rebuilds++;
        }
    }

    return [
        'ok' => true,
        'read_only' => true,
        'data_jobs' => $jobs,
        'flags' => [
            'contact_emails_v1' => $flag('contact_emails_v1'),
            'contacts_list_cols_v1' => $flag('contacts_list_cols_v1'),
            'contacts_schema' => $flag('contacts_schema_v1'),
        ],
        'inkorg_list' => [
            'stamp' => $stamp,
            'fingerprint' => $fingerprint,
            'match' => $stamp !== '' && $stamp === $fingerprint,
        ],
        'rebuilds_last_hour' => $rebuilds,
    ];
}

function jeab_pdo_resolve_known_busy(PDO $pdo, string $driver): void
{
    if (jeab_settings_value($pdo, $driver, 'db_busy_resolved_v1') === '1') {
        return;
    }
    if (!function_exists('jeab_log_app_error_resolved_put')) {
        return;
    }
    $GLOBALS['_JEAB_PDO_RESOLVE_RUNS'] = (int) ($GLOBALS['_JEAB_PDO_RESOLVE_RUNS'] ?? 0) + 1;
    // Flaggan före loggskrivningen. Den öppnar butiken och kan anropa den här
    // funktionen igen innan schemat är stämplat.
    jeab_settings_put($pdo, $driver, 'db_busy_resolved_v1', '1');
    foreach (['7c5afc64', '74ae69c5', '42bacec3', 'dbbb737a'] as $fp) {
        try {
            jeab_log_app_error_resolved_put($fp, 'einstein', '1203');
        } catch (Throwable) {
        }
    }
}

/** En kalenderladdning håller högst en anslutning. Under Graph är den släppt. */
function jeab_calendar_db_peak(): int
{
    return 1;
}

function jeab_pdo_alive(): WeakMap
{
    static $map;
    if (!$map instanceof WeakMap) {
        $map = new WeakMap();
    }

    return $map;
}

function jeab_pdo_track(PDO $pdo): void
{
    jeab_pdo_alive()[$pdo] = true;
    jeab_pdo_held_count();
}

/** Antal PDO-objekt som fortfarande har en referens. Poolen kan vara tom ändå. */
function jeab_pdo_held_count(): int
{
    $n = count(jeab_pdo_alive());
    if ($n > (int) ($GLOBALS['_JEAB_PDO_HELD_PEAK'] ?? 0)) {
        $GLOBALS['_JEAB_PDO_HELD_PEAK'] = $n;
    }

    return $n;
}

function jeab_pdo_held_peak(): int
{
    return (int) ($GLOBALS['_JEAB_PDO_HELD_PEAK'] ?? 0);
}

function jeab_pdo_any_tx(): bool
{
    foreach (jeab_pdo_alive() as $pdo => $on) {
        unset($on);
        if ($pdo instanceof PDO && jeab_pdo_tx_busy($pdo)) {
            return true;
        }
    }

    return false;
}

/**
 * En fråga. 1203 försöks inte om. Bara anslutningen i jeab_pdo_open_once gör det.
 * 2006 och 2013 hanteras i jeab_pdo_gone_retry: en gång, aldrig tyst inuti en transaktion.
 *
 * @template T
 * @param callable(): T $fn
 * @return T
 */
function jeab_pdo_call(callable $fn): mixed
{
    if (jeab_pdo_any_tx()) {
        return $fn();
    }
    try {
        return $fn();
    } catch (PDOException $e) {
        if (jeab_pdo_is_max_user_connections($e)) {
            jeab_pdo_busy_fail($e);
        }
        throw $e;
    }
}

function jeab_pdo_mysql_gone_code(PDOException $e): int
{
    $info = $e->errorInfo ?? null;
    if (is_array($info) && isset($info[1]) && is_numeric($info[1])) {
        $code = (int) $info[1];
        if ($code === 2006 || $code === 2013) {
            return $code;
        }
    }
    if (is_numeric($e->getCode())) {
        $code = (int) $e->getCode();
        if ($code === 2006 || $code === 2013) {
            return $code;
        }
    }
    $msg = $e->getMessage();
    if (str_contains($msg, 'Lost connection')) {
        return 2013;
    }

    return 2006;
}

/** 2006 server has gone away, 2013 Lost connection. Ingen SQL och ingen kundtext. */
function jeab_pdo_mysql_gone(Throwable $e): bool
{
    if (!$e instanceof PDOException) {
        return false;
    }
    $info = $e->errorInfo ?? null;
    if (is_array($info) && isset($info[1]) && is_numeric($info[1])) {
        $code = (int) $info[1];
        if ($code === 2006 || $code === 2013) {
            return true;
        }
    }
    if (is_numeric($e->getCode())) {
        $code = (int) $e->getCode();
        if ($code === 2006 || $code === 2013) {
            return true;
        }
    }
    $msg = $e->getMessage();

    return str_contains($msg, 'server has gone away') || str_contains($msg, 'Lost connection');
}

/** Bara felkod och ett kort ord (exec, prepare, mail). Ingen mejltext. */
function jeab_pdo_log_gone(PDOException $e, string $context): void
{
    $context = strtolower($context);
    $context = preg_replace('/[^a-z0-9_-]/', '', $context) ?? '';
    if ($context === '') {
        $context = 'db';
    }
    if (!function_exists('jeab_log_app_error')) {
        return;
    }
    jeab_log_app_error('db', (string) jeab_pdo_mysql_gone_code($e) . ' ' . $context, [
        'skip_request' => true,
        'no_webhook' => true,
    ]);
}

function jeab_pdo_stmt_map(): WeakMap
{
    static $map;
    $map ??= new WeakMap();

    return $map;
}

function jeab_pdo_bind_stmt(PDO $pdo, mixed $stmt): mixed
{
    if ($stmt instanceof PDOStatement) {
        jeab_pdo_stmt_map()[$stmt] = $pdo;
    }

    return $stmt;
}

function jeab_pdo_stmt_owner(PDOStatement $stmt): ?PDO
{
    $map = jeab_pdo_stmt_map();
    if (isset($map[$stmt]) && $map[$stmt] instanceof PDO) {
        return $map[$stmt];
    }

    return jeab_pdo_pool_current();
}

function jeab_pdo_meta_map(): WeakMap
{
    static $map;
    $map ??= new WeakMap();

    return $map;
}

function jeab_pdo_replaced_map(): WeakMap
{
    static $map;
    $map ??= new WeakMap();

    return $map;
}

function jeab_pdo_replaced(PDO $dead): ?PDO
{
    $map = jeab_pdo_replaced_map();
    $pdo = $map[$dead] ?? null;

    return $pdo instanceof PDO ? $pdo : null;
}

function jeab_pdo_pool_current(): ?PDO
{
    foreach ($GLOBALS['_JEAB_PDO_POOL'] ?? [] as $pdo) {
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }

    return null;
}

/** Glöm den döda anslutningen. Transaktionsflaggan nollas så nästa enhet börjar rent. */
function jeab_pdo_gone_break(PDO $dead): void
{
    $tx = jeab_pdo_tx_map();
    unset($tx[$dead]);
    $raw = jeab_pdo_raw_tx_map();
    $hadRaw = isset($raw[$dead]);
    unset($raw[$dead]);
    $GLOBALS['_JEAB_PDO_GONE_BREAK'] = true;
    try {
        if ($hadRaw) {
            try {
                $dead->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        try {
            if ($dead->inTransaction()) {
                $dead->rollBack();
            }
        } catch (Throwable) {
        }
    } finally {
        unset($GLOBALS['_JEAB_PDO_GONE_BREAK']);
    }
}

function jeab_pdo_forget(PDO $dead): void
{
    $pool = $GLOBALS['_JEAB_PDO_POOL'] ?? [];
    if (is_array($pool)) {
        foreach ($pool as $key => $pdo) {
            if ($pdo === $dead) {
                unset($GLOBALS['_JEAB_PDO_POOL'][$key]);
            }
        }
    }
    $alive = jeab_pdo_alive();
    unset($alive[$dead]);
    $tx = jeab_pdo_tx_map();
    unset($tx[$dead]);
    $raw = jeab_pdo_raw_tx_map();
    unset($raw[$dead]);
}

function jeab_pdo_adopt(PDO $dead, PDO $fresh): void
{
    $store = $GLOBALS['_JEAB_ACTIVE_STORE'] ?? null;
    if ($store instanceof JeabPdoStore && ($store->holdsPdo($dead) || !$store->hasPdo())) {
        $store->adoptPdo($fresh);
    }
    $ink = $GLOBALS['_JEAB_INKORG_STORE'] ?? null;
    if ($ink instanceof JeabPdoInkorgStore && ($ink->holdsPdo($dead) || !$ink->hasPdo())) {
        $ink->adoptPdo($fresh);
    }
}

/** Släpp cachen och öppna en ny anslutning mot samma databas. */
function jeab_pdo_replace(PDO $dead): PDO
{
    $known = jeab_pdo_replaced($dead);
    if ($known instanceof PDO) {
        jeab_pdo_adopt($dead, $known);

        return $known;
    }
    if (!empty($GLOBALS['_JEAB_PDO_REPLACING'])) {
        $busy = new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', 2006);
        $busy->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
        throw $busy;
    }
    $driver = '';
    $meta = jeab_pdo_meta_map();
    if (isset($meta[$dead]) && is_array($meta[$dead])) {
        $driver = (string) ($meta[$dead]['driver'] ?? '');
    }
    $GLOBALS['_JEAB_PDO_REPLACING'] = true;
    try {
        jeab_pdo_gone_break($dead);
        jeab_pdo_forget($dead);
        $fresh = $driver === 'mysql' ? jeab_pdo_connect_mysql() : jeab_pdo_connect_sqlite();
        jeab_pdo_replaced_map()[$dead] = $fresh;
        jeab_pdo_adopt($dead, $fresh);
        $GLOBALS['_JEAB_PDO_LAST_USED'] = microtime(true);

        return $fresh;
    } finally {
        unset($GLOBALS['_JEAB_PDO_REPLACING']);
    }
}

/** Tester: en PDOException i _JEAB_PDO_EXEC_FAIL kastas en gång från nästa sats. */
function jeab_pdo_fail_once_throw(): void
{
    $fail = $GLOBALS['_JEAB_PDO_EXEC_FAIL'] ?? null;
    if (!$fail instanceof PDOException) {
        return;
    }
    unset($GLOBALS['_JEAB_PDO_EXEC_FAIL']);
    throw $fail;
}

function jeab_pdo_sql_flat(string $sql): string
{
    $sql = preg_replace('/\/\*.*?\*\//s', ' ', $sql) ?? $sql;
    $sql = preg_replace('/--[^\n]*/', ' ', $sql) ?? $sql;
    $sql = str_replace(["\r", "\n", "\t"], ' ', $sql);
    $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;
    $sql = trim($sql);
    $sql = preg_replace('/^\(\s*/', '', $sql) ?? $sql;

    return rtrim($sql, " \t;");
}

/** Tar bort strängar och parenteser så WHERE inuti en underråga inte räknas. */
function jeab_pdo_sql_strip_nested(string $sql): string
{
    $out = '';
    $depth = 0;
    $quote = '';
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($quote !== '') {
            if ($c === $quote && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $quote = '';
            }
            $out .= ' ';
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') {
            $quote = $c;
            $out .= ' ';
            continue;
        }
        if ($c === '(') {
            $depth++;
            $out .= ' ';
            continue;
        }
        if ($c === ')') {
            $depth = max(0, $depth - 1);
            $out .= ' ';
            continue;
        }
        $out .= $depth > 0 ? ' ' : $c;
    }

    return preg_replace('/\s+/', ' ', $out) ?? $out;
}

/**
 * 2013 på en skrivning kan redan vara committad. Bara läsning och idempotenta
 * skrivningar får köras om. Vanlig INSERT får inte det.
 */
function jeab_pdo_sql_retry_safe(string $sql): bool
{
    $flat = jeab_pdo_sql_flat($sql);
    if ($flat === '' || str_contains($flat, ';')) {
        return false;
    }
    if (preg_match('/^(SELECT|SHOW|EXPLAIN|DESCRIBE|DESC|PRAGMA|SET)\b/i', $flat) === 1) {
        return true;
    }
    if (preg_match('/^INSERT\s+(OR\s+IGNORE\s+|IGNORE\s+)/i', $flat) === 1) {
        return true;
    }
    if (preg_match('/^INSERT\s+OR\s+REPLACE\b/i', $flat) === 1 || preg_match('/^REPLACE\b/i', $flat) === 1) {
        return true;
    }
    if (preg_match('/^INSERT\b/i', $flat) === 1 && preg_match('/\bON\s+DUPLICATE\s+KEY\b/i', $flat) === 1) {
        return true;
    }
    if (preg_match('/^(UPDATE|DELETE)\b/i', $flat) !== 1) {
        return false;
    }
    $bare = jeab_pdo_sql_strip_nested($flat);
    if (preg_match('/\bWHERE\b\s*(.+)$/i', $bare, $match) !== 1) {
        return false;
    }
    $where = trim((string) $match[1]);
    $where = preg_replace('/\b(ORDER\s+BY|LIMIT|RETURNING)\b.*$/i', '', $where) ?? $where;
    $where = trim($where);
    if ($where === '' || preg_match('/^(1|0|true|false|1\s*=\s*1|0\s*=\s*0)$/i', $where) === 1) {
        return false;
    }

    return preg_match('/\b[a-z_][a-z0-9_]*\b\s*(=|IN\b)/i', $where) === 1;
}

/**
 * inTransaction() kastade för att anslutningen redan är död.
 * Bara en läsning får då räknas som «inte i transaktion» och köras om en gång.
 */
function jeab_pdo_gone_read_retry(Throwable $e, string $sql): bool
{
    if (!$e instanceof PDOException || !jeab_pdo_mysql_gone($e)) {
        return false;
    }
    $flat = jeab_pdo_sql_flat($sql);
    if ($flat === '' || str_contains($flat, ';')) {
        return false;
    }

    return preg_match('/^(SELECT|SHOW|EXPLAIN|DESCRIBE|DESC|PRAGMA|WITH)\b/i', $flat) === 1;
}

function jeab_pdo_gone_may_retry(int $code, string $context, string $sql): bool
{
    if ($code !== 2013) {
        return true;
    }
    if ($context === 'prepare' || $context === 'ping') {
        return true;
    }

    return jeab_pdo_sql_retry_safe($sql);
}

/**
 * Ett anrop. Utan transaktion: ny anslutning och samma säkra sats en gång till.
 * 2013 på vanlig INSERT: ny anslutning och kasta om (servern kan redan ha skrivit).
 * Med transaktion: rulla tillbaka, ny anslutning, kasta om. Yttersta jeab_pdo_transaction
 * kör hela enheten en gång till.
 *
 * @template T
 * @param callable(): T $run
 * @param callable(PDO): T $again
 * @return T
 */
function jeab_pdo_gone_retry(PDO $pdo, string $context, callable $run, callable $again, string $sql = ''): mixed
{
    try {
        return $run();
    } catch (PDOException $e) {
        if (!jeab_pdo_mysql_gone($e)) {
            throw $e;
        }
        if (!empty($GLOBALS['_JEAB_PDO_COMMITTING'])) {
            jeab_pdo_raise_commit_unknown($e, $pdo);
        }
        $depth = (int) ($GLOBALS['_JEAB_PDO_GONE_DEPTH'] ?? 0);
        jeab_pdo_log_gone($e, $context);
        $inTx = jeab_pdo_tx_depth($pdo) > 0;
        if (!$inTx) {
            try {
                if (($GLOBALS['_JEAB_PDO_IN_TX_THROWS'] ?? null) instanceof Throwable) {
                    $probe = $GLOBALS['_JEAB_PDO_IN_TX_THROWS'];
                    unset($GLOBALS['_JEAB_PDO_IN_TX_THROWS']);
                    throw $probe;
                }
                $inTx = jeab_pdo_any_tx() || $pdo->inTransaction();
            } catch (Throwable $probe) {
                // Död anslutning kastar i inTransaction(). En läsning får
                // återanslutas och köras en gång till. En skrivning får inte det.
                $inTx = !jeab_pdo_gone_read_retry($probe, $sql);
            }
        }
        if ($inTx || $depth >= 1) {
            if ($inTx) {
                $GLOBALS['_JEAB_PDO_GONE_GEN'] = (int) ($GLOBALS['_JEAB_PDO_GONE_GEN'] ?? 0) + 1;
                jeab_pdo_gone_break($pdo);
                if ($depth < 1) {
                    jeab_pdo_replace($pdo);
                }
            }
            throw $e;
        }
        if (!jeab_pdo_gone_may_retry(jeab_pdo_mysql_gone_code($e), $context, $sql)) {
            jeab_pdo_replace($pdo);
            throw $e;
        }
        $GLOBALS['_JEAB_PDO_GONE_DEPTH'] = $depth + 1;
        try {
            $fresh = jeab_pdo_replace($pdo);

            return $again($fresh);
        } finally {
            $GLOBALS['_JEAB_PDO_GONE_DEPTH'] = $depth;
        }
    }
}

/**
 * Lång import. Pingar bara när anslutningen legat stilla.
 * MySQL som redan är borta byts ut. Sqlite rörs inte.
 */
function jeab_pdo_ping_if_idle(?int $idleSeconds = null): void
{
    if (jeab_pdo_any_tx()) {
        return;
    }
    $limit = $idleSeconds ?? (int) ($GLOBALS['_JEAB_PDO_IDLE_SECONDS'] ?? 10);
    if ($limit < 0) {
        $limit = 0;
    }
    $last = (float) ($GLOBALS['_JEAB_PDO_LAST_USED'] ?? 0);
    if ($last > 0 && (microtime(true) - $last) < $limit) {
        return;
    }
    $pdo = jeab_pdo_pool_current();
    if (!$pdo instanceof PDO) {
        return;
    }
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    } catch (PDOException $e) {
        if (jeab_pdo_mysql_gone($e)) {
            jeab_pdo_log_gone($e, 'ping');
            jeab_pdo_replace($pdo);
        }

        return;
    }
    if ($driver !== 'mysql') {
        $GLOBALS['_JEAB_PDO_LAST_USED'] = microtime(true);

        return;
    }
    try {
        $pdo->query('SELECT 1');
    } catch (PDOException $e) {
        if (!jeab_pdo_mysql_gone($e)) {
            return;
        }
    }
}

/**
 * Ett mejl. 2006/2013: den cachade anslutningen byts och samma mejl körs en gång till.
 * Anroparen ska vara idempotent på message id.
 *
 * @template T
 * @param callable(): T $fn
 * @return T
 */
function jeab_pdo_retry_mail(callable $fn): mixed
{
    jeab_pdo_ping_if_idle();
    try {
        return $fn();
    } catch (PDOException $e) {
        if (!jeab_pdo_mysql_gone($e)) {
            throw $e;
        }
        jeab_pdo_log_gone($e, 'mail');
        $current = jeab_pdo_pool_current();
        if ($current instanceof PDO) {
            try {
                jeab_pdo_replace($current);
            } catch (Throwable) {
            }
        }

        return $fn();
    }
}

/** @param array<int|string, mixed> $opts */
function jeab_pdo_open_new(string $dsn, ?string $user, ?string $pass, array $opts): PDO
{
    $factory = $GLOBALS['_JEAB_PDO_FACTORY'] ?? null;
    if (is_callable($factory)) {
        $pdo = $factory($dsn, $user, $pass, $opts);
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('PDO-fabriken returnerade ingen anslutning.');
        }
        jeab_pdo_track($pdo);

        return $pdo;
    }

    $pdo = new JeabTracePdo($dsn, $user ?? '', $pass, $opts);
    jeab_pdo_track($pdo);

    return $pdo;
}

/** @return array<int, mixed> */
function jeab_pdo_mysql_names_sql(): string
{
    return 'SET NAMES utf8mb4 COLLATE utf8mb4_swedish_ci';
}

/** Tom sträng i config är inte null; DSN behöver utf8mb4. */
function jeab_pdo_mysql_charset(mixed $charset): string
{
    $value = trim((string) $charset);

    return $value === '' ? 'utf8mb4' : $value;
}

function jeab_pdo_log_charset(string $message): void
{
    $message = trim($message);
    if ($message === '') {
        return;
    }
    if (function_exists('jeab_log_app_error')) {
        jeab_log_app_error('db', $message, [
            'skip_request' => true,
            'no_webhook' => true,
        ]);
    }
    error_log($message);
}

/**
 * SET NAMES när anslutningen inte redan fick det via MYSQL_ATTR_INIT_COMMAND.
 * @@character_set_connection läses inte: den frågan är en extra runda per anslutning.
 * Loggar, kastar inte.
 */
function jeab_pdo_mysql_ensure_utf8mb4(PDO $pdo): void
{
    if (defined('PDO::MYSQL_ATTR_INIT_COMMAND') && empty($GLOBALS['_JEAB_PDO_NAMES_FORCE'])) {
        return;
    }
    try {
        $pdo->exec(jeab_pdo_mysql_names_sql());
    } catch (Throwable $e) {
        jeab_pdo_log_charset('SET NAMES misslyckades: ' . $e->getMessage());
    }
}

function jeab_pdo_mysql_warn_unless_utf8mb4(PDO $pdo): void
{
    $got = '';
    try {
        $stmt = $pdo->query('SELECT @@character_set_connection');
        $got = $stmt instanceof PDOStatement ? trim((string) $stmt->fetchColumn()) : '';
    } catch (Throwable $e) {
        jeab_pdo_log_charset('@@character_set_connection kunde inte läsas: ' . $e->getMessage());

        return;
    }
    if (strcasecmp($got, 'utf8mb4') === 0) {
        return;
    }
    jeab_pdo_log_charset('@@character_set_connection=' . $got . ' (förväntat utf8mb4)');
}

function jeab_pdo_base_options(): array
{
    return [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_PERSISTENT => false,
    ];
}

/** Klick med X-Jeab-Interactive: 1. Förhämtning skickar inte den headern. */
function jeab_pdo_interactive_request(): bool
{
    if (array_key_exists('_JEAB_PDO_INTERACTIVE', $GLOBALS)) {
        return (bool) $GLOBALS['_JEAB_PDO_INTERACTIVE'];
    }
    if (PHP_SAPI === 'cli') {
        return false;
    }
    $hdr = strtolower(trim((string) ($_SERVER['HTTP_X_JEAB_INTERACTIVE'] ?? '')));

    return $hdr === '1';
}

/** Sidan och klicket får sova högst 300 ms totalt. Bakgrund sover inte alls. */
function jeab_pdo_busy_budget_us(): int
{
    $cap = (int) ($GLOBALS['_JEAB_PDO_INTERACTIVE_BUDGET_US'] ?? 300000);

    return max(0, $cap);
}

/** @return bool false när nästa sömn skulle överskrida det interaktiva taket */
function jeab_pdo_busy_wait(int $attempt): bool
{
    if (jeab_pdo_is_background()) {
        return false;
    }
    $base = (int) ($GLOBALS['_JEAB_PDO_RETRY_US'] ?? 80000);
    if ($base <= 0) {
        return true;
    }
    $sleep = $base * max(1, $attempt);
    $budget = jeab_pdo_busy_budget_us();
    if ($budget > 0) {
        $used = (int) ($GLOBALS['_JEAB_PDO_WAITED_US'] ?? 0);
        $room = $budget - $used;
        if ($room <= 0) {
            return false;
        }
        if ($sleep > $room) {
            $sleep = $room;
        }
    }
    usleep($sleep);
    $GLOBALS['_JEAB_PDO_WAITED_US'] = (int) ($GLOBALS['_JEAB_PDO_WAITED_US'] ?? 0) + $sleep;

    return true;
}

/**
 * Väntan enbart för jeab_pdo_open_once. Frågor och transaktioner använder den inte.
 *
 * @return bool false när nästa sömn skulle överskrida taket
 */
function jeab_pdo_open_wait(int $attempt): bool
{
    $base = (int) ($GLOBALS['_JEAB_PDO_RETRY_US'] ?? 80000);
    if ($base <= 0) {
        return true;
    }
    $sleep = $base * max(1, $attempt);
    $budget = jeab_pdo_is_background()
        ? max(0, (int) ($GLOBALS['_JEAB_PDO_BG_BUDGET_US'] ?? 120000))
        : jeab_pdo_busy_budget_us();
    if ($budget > 0) {
        $used = (int) ($GLOBALS['_JEAB_PDO_WAITED_US'] ?? 0);
        $room = $budget - $used;
        if ($room <= 0) {
            return false;
        }
        if ($sleep > $room) {
            $sleep = $room;
        }
    }
    usleep($sleep);
    $GLOBALS['_JEAB_PDO_WAITED_US'] = (int) ($GLOBALS['_JEAB_PDO_WAITED_US'] ?? 0) + $sleep;

    return true;
}

/** @param array<int|string, mixed> $opts */
function jeab_pdo_open_once(string $dsn, ?string $user, ?string $pass, array $opts): PDO
{
    $last = null;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        try {
            return jeab_pdo_open_new($dsn, $user, $pass, $opts);
        } catch (PDOException $e) {
            $last = $e;
            if (!jeab_pdo_is_max_user_connections($e) || $attempt === 3 || !jeab_pdo_open_wait($attempt)) {
                if (jeab_pdo_is_max_user_connections($e)) {
                    jeab_pdo_busy_fail($e);
                }
                throw $e;
            }
        }
    }
    if ($last instanceof PDOException && jeab_pdo_is_max_user_connections($last)) {
        jeab_pdo_busy_fail($last);
    }
    throw $last instanceof Throwable ? $last : new JeabDbBusyException(jeab_pdo_busy_message());
}

function jeab_pdo_pool_get(string $key): ?PDO
{
    $pdo = $GLOBALS['_JEAB_PDO_POOL'][$key] ?? null;

    return $pdo instanceof PDO ? $pdo : null;
}

function jeab_pdo_pool_put(string $key, PDO $pdo): PDO
{
    if (!isset($GLOBALS['_JEAB_PDO_POOL']) || !is_array($GLOBALS['_JEAB_PDO_POOL'])) {
        $GLOBALS['_JEAB_PDO_POOL'] = [];
    }
    $GLOBALS['_JEAB_PDO_POOL'][$key] = $pdo;
    $GLOBALS['_JEAB_PDO_OPENS'] = (int) ($GLOBALS['_JEAB_PDO_OPENS'] ?? 0) + 1;
    $driver = '';
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    } catch (Throwable) {
        $driver = str_starts_with($key, 'mysql:') ? 'mysql' : 'sqlite';
    }
    jeab_pdo_meta_map()[$pdo] = ['driver' => $driver, 'key' => $key];
    jeab_pdo_note_connect($pdo);

    return $pdo;
}

function jeab_pdo_release(): void
{
    $live = [];
    foreach (jeab_pdo_alive() as $pdo => $on) {
        unset($on);
        if ($pdo instanceof PDO) {
            $live[] = $pdo;
        }
    }
    foreach ($live as $pdo) {
        $map = jeab_pdo_alive();
        unset($map[$pdo]);
    }
    $GLOBALS['_JEAB_PDO_POOL'] = [];
    $GLOBALS['_JEAB_SIDE_SQLITE'] = null;
    if (function_exists('jeab_stores_disconnect')) {
        jeab_stores_disconnect();
    }
    jeab_pdo_bg_unlock();
}

/** Lägg anslutningen kvar över ett läsanrop som följs av en databasrad. */
function jeab_pdo_hold_push(): void
{
    $GLOBALS['_JEAB_PDO_HOLD_DEPTH'] = (int) ($GLOBALS['_JEAB_PDO_HOLD_DEPTH'] ?? 0) + 1;
    $GLOBALS['_JEAB_PDO_HOLD'] = true;
}

function jeab_pdo_hold_pop(): void
{
    $depth = (int) ($GLOBALS['_JEAB_PDO_HOLD_DEPTH'] ?? 0) - 1;
    if ($depth <= 0) {
        $GLOBALS['_JEAB_PDO_HOLD_DEPTH'] = 0;
        unset($GLOBALS['_JEAB_PDO_HOLD']);

        return;
    }
    $GLOBALS['_JEAB_PDO_HOLD_DEPTH'] = $depth;
    $GLOBALS['_JEAB_PDO_HOLD'] = true;
}

/** Släpp anslutningen före ett läsanrop. En öppen transaktion får ligga kvar. */
function jeab_pdo_release_if_idle(): void
{
    foreach ($GLOBALS['_JEAB_PDO_POOL'] ?? [] as $pdo) {
        if ($pdo instanceof PDO && jeab_pdo_tx_busy($pdo)) {
            return;
        }
    }
    jeab_pdo_release();
}

function jeab_pdo_tx_map(): WeakMap
{
    static $map;
    $map ??= new WeakMap();

    return $map;
}

function jeab_pdo_tx_depth(PDO $pdo): int
{
    $map = jeab_pdo_tx_map();

    return isset($map[$pdo]) ? (int) $map[$pdo] : 0;
}

/** Sant när den här anslutningen redan har en transaktion. DDL får inte köras då. */
function jeab_pdo_tx_busy(PDO $pdo): bool
{
    return jeab_pdo_tx_depth($pdo) > 0 || jeab_pdo_in_tx($pdo);
}

/**
 * sqlite-transaktioner som startats med exec('BEGIN IMMEDIATE').
 * PHP < 8.4 räknar inte dem i PDO::inTransaction(), så vi håller reda själva.
 */
function jeab_pdo_raw_tx_map(): WeakMap
{
    static $map;
    $map ??= new WeakMap();

    return $map;
}

/** Sant om PDO eller en egen BEGIN IMMEDIATE har en öppen transaktion. */
function jeab_pdo_in_tx(PDO $pdo): bool
{
    $raw = jeab_pdo_raw_tx_map();

    return $pdo->inTransaction() || isset($raw[$pdo]);
}

/** sqlite: BEGIN IMMEDIATE (skrivlås direkt). Övriga: beginTransaction. */
function jeab_pdo_begin_immediate(PDO $pdo): void
{
    if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('BEGIN IMMEDIATE');
        $raw = jeab_pdo_raw_tx_map();
        $raw[$pdo] = true;

        return;
    }
    $pdo->beginTransaction();
}

/**
 * COMMIT kan ha nått servern fast svaret föll bort. Kastas vidare och körs inte om.
 */
function jeab_pdo_raise_commit_unknown(PDOException $e, ?PDO $pdo = null): never
{
    jeab_pdo_log_gone($e, 'commit');
    if ($pdo instanceof PDO && empty($GLOBALS['_JEAB_PDO_REPLACING'])) {
        try {
            jeab_pdo_replace($pdo);
        } catch (Throwable) {
        }
    }
    $code = jeab_pdo_mysql_gone_code($e);
    throw new JeabPdoCommitUnknownException('mysql ' . $code . ' commit', 0, $e);
}

/** Skickar COMMIT. 2006/2013 blir JeabPdoCommitUnknownException, inte ett omförsök. */
function jeab_pdo_send_commit(PDO $pdo, callable $send): void
{
    $GLOBALS['_JEAB_PDO_COMMITTING'] = true;
    try {
        $fail = $GLOBALS['_JEAB_PDO_COMMIT_FAIL'] ?? null;
        if ($fail instanceof PDOException) {
            unset($GLOBALS['_JEAB_PDO_COMMIT_FAIL']);
            throw $fail;
        }
        $send();
    } catch (JeabPdoCommitUnknownException $e) {
        throw $e;
    } catch (PDOException $e) {
        if (jeab_pdo_mysql_gone($e)) {
            jeab_pdo_raise_commit_unknown($e, $pdo);
        }
        throw $e;
    } finally {
        unset($GLOBALS['_JEAB_PDO_COMMITTING']);
    }
}

/** Avslutar en transaktion från jeab_pdo_begin_immediate eller beginTransaction. */
function jeab_pdo_end(PDO $pdo, bool $commit): void
{
    $raw = jeab_pdo_raw_tx_map();
    if (isset($raw[$pdo])) {
        unset($raw[$pdo]);
        if ($commit) {
            jeab_pdo_send_commit($pdo, static function () use ($pdo): void {
                $pdo->exec('COMMIT');
            });

            return;
        }
        $pdo->exec('ROLLBACK');

        return;
    }
    if (!$pdo->inTransaction()) {
        return;
    }
    if ($commit) {
        jeab_pdo_send_commit($pdo, static function () use ($pdo): void {
            $pdo->commit();
        });

        return;
    }
    $pdo->rollBack();
}

function jeab_pdo_tx_closed_error(Throwable $e): bool
{
    $msg = $e->getMessage();

    return str_contains($msg, 'no active transaction')
        || str_contains($msg, 'no transaction is active');
}

function jeab_pdo_tx_finish(PDO $pdo, bool $commit): void
{
    $raw = jeab_pdo_raw_tx_map();
    if (isset($raw[$pdo])) {
        jeab_pdo_end($pdo, $commit);

        return;
    }
    if (!$pdo->inTransaction()) {
        return;
    }
    try {
        if ($commit) {
            jeab_pdo_send_commit($pdo, static function () use ($pdo): void {
                $pdo->commit();
            });
        } else {
            $pdo->rollBack();
        }
    } catch (JeabPdoCommitUnknownException $e) {
        throw $e;
    } catch (Throwable $e) {
        try {
            $still = $pdo->inTransaction();
        } catch (Throwable) {
            $still = false;
        }
        if ($still && !jeab_pdo_tx_closed_error($e)) {
            throw $e;
        }
    }
}

/**
 * En PDO, en transaktion. Nästlade anrop delar den yttersta.
 * Bara djup 0 kör beginTransaction och commit eller rollBack.
 * 1203 kör inte om $fn. Bara anslutningen i jeab_pdo_open_once gör det.
 * 2006 och 2013 före COMMIT: yttersta anropet kör hela enheten en gång till.
 * 2006 och 2013 under COMMIT: JeabPdoCommitUnknownException, enheten körs inte om.
 *
 * @template T
 * @param callable(PDO): T $fn
 * @return T
 */
function jeab_pdo_transaction(PDO $pdo, callable $fn): mixed
{
    $GLOBALS['_JEAB_PDO_TX_STACK'] = (int) ($GLOBALS['_JEAB_PDO_TX_STACK'] ?? 0) + 1;
    try {
        $current = $pdo;
        $retry = true;
        while (true) {
            try {
                return jeab_pdo_transaction_once($current, $fn);
            } catch (JeabPdoCommitUnknownException $e) {
                throw $e;
            } catch (PDOException $e) {
                $outer = (int) ($GLOBALS['_JEAB_PDO_TX_STACK'] ?? 0) === 1;
                if (!$outer || !$retry || !jeab_pdo_mysql_gone($e)) {
                    throw $e;
                }
                $retry = false;
                $next = jeab_pdo_replaced($current);
                if (!$next instanceof PDO) {
                    $next = jeab_pdo_replace($current);
                }
                $current = $next;
            }
        }
    } finally {
        $left = (int) ($GLOBALS['_JEAB_PDO_TX_STACK'] ?? 1) - 1;
        if ($left <= 0) {
            unset($GLOBALS['_JEAB_PDO_TX_STACK']);
        } else {
            $GLOBALS['_JEAB_PDO_TX_STACK'] = $left;
        }
    }
}

/**
 * @template T
 * @param callable(PDO): T $fn
 * @return T
 */
function jeab_pdo_transaction_once(PDO $pdo, callable $fn): mixed
{
    $map = jeab_pdo_tx_map();
    $depth = isset($map[$pdo]) ? (int) $map[$pdo] : 0;
    $owner = $depth === 0 && !jeab_pdo_in_tx($pdo);
    if ($owner) {
        try {
            $pdo->beginTransaction();
        } catch (Throwable $e) {
            if (jeab_pdo_is_max_user_connections($e)) {
                jeab_pdo_busy_fail($e);
            }
            throw $e;
        }
    }
    $map[$pdo] = $depth + 1;
    $goneMark = (int) ($GLOBALS['_JEAB_PDO_GONE_GEN'] ?? 0);
    try {
        $result = $fn($pdo);
        if ($owner && (int) ($GLOBALS['_JEAB_PDO_GONE_GEN'] ?? 0) !== $goneMark) {
            $gone = new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', 2006);
            $gone->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
            throw $gone;
        }
        if ($depth === 0) {
            unset($map[$pdo]);
        } else {
            $map[$pdo] = $depth;
        }
        if ($owner) {
            jeab_pdo_tx_finish($pdo, true);
        }

        return $result;
    } catch (Throwable $e) {
        if ($depth === 0) {
            unset($map[$pdo]);
        } else {
            $map[$pdo] = $depth;
        }
        if ($owner && !$e instanceof JeabPdoCommitUnknownException) {
            try {
                jeab_pdo_tx_finish($pdo, false);
            } catch (Throwable) {
            }
        }
        if ($owner && jeab_pdo_is_max_user_connections($e)) {
            jeab_pdo_busy_fail($e);
        }
        throw $e;
    }
}

function jeab_db_slot_dir(): string
{
    $base = function_exists('jeab_data_dir') ? jeab_data_dir() : sys_get_temp_dir();
    $dir = rtrim(str_replace('\\', '/', $base), '/') . '/.db-slots';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir;
}

/** @return resource|null  null = upptaget, eller låsfilen gick inte att skapa */
function jeab_db_slot_acquire(int $max = 2)
{
    $max = max(1, $max);
    $dir = jeab_db_slot_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }
    for ($i = 0; $i < $max; $i++) {
        $fh = @fopen($dir . '/slot-' . $i . '.lock', 'c');
        if ($fh === false) {
            continue;
        }
        if (flock($fh, LOCK_EX | LOCK_NB)) {
            return $fh;
        }
        fclose($fh);
    }

    return null;
}

/** @param resource|null $fh */
function jeab_db_slot_release($fh): void
{
    if (!is_resource($fh)) {
        return;
    }
    flock($fh, LOCK_UN);
    fclose($fh);
}

/**
 * tick.php (var 5:e minut) och sms-cron får inte hålla varsin MySQL samtidigt.
 * Samma process får nästla. En annan process får busy och öppnar ingen anslutning.
 */
function jeab_job_overlap_acquire(int $waitMs = 0): bool
{
    $depth = (int) ($GLOBALS['_JEAB_JOB_OVERLAP_DEPTH'] ?? 0);
    if ($depth > 0) {
        $GLOBALS['_JEAB_JOB_OVERLAP_DEPTH'] = $depth + 1;

        return true;
    }
    $deadline = microtime(true) + max(0, $waitMs) / 1000;
    do {
        if (jeab_job_overlap_try()) {
            return true;
        }
        if ($waitMs <= 0 || microtime(true) >= $deadline) {
            return false;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);

    return jeab_job_overlap_try();
}

function jeab_job_overlap_try(): bool
{
    $dir = jeab_db_slot_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $fh = @fopen($dir . '/job-overlap.lock', 'c');
    if ($fh === false) {
        return false;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);

        return false;
    }
    $GLOBALS['_JEAB_JOB_OVERLAP'] = $fh;
    $GLOBALS['_JEAB_JOB_OVERLAP_DEPTH'] = 1;

    return true;
}

function jeab_job_overlap_release(): void
{
    $depth = (int) ($GLOBALS['_JEAB_JOB_OVERLAP_DEPTH'] ?? 0);
    if ($depth > 1) {
        $GLOBALS['_JEAB_JOB_OVERLAP_DEPTH'] = $depth - 1;

        return;
    }
    $fh = $GLOBALS['_JEAB_JOB_OVERLAP'] ?? null;
    if (is_resource($fh)) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    unset($GLOBALS['_JEAB_JOB_OVERLAP']);
    $GLOBALS['_JEAB_JOB_OVERLAP_DEPTH'] = 0;
}

/**
 * sha256 av schema/sqlite.php + "\n" + schema/mysql.php.
 * När filerna ändras ska den här pin:en räknas om. Schema-id innehåller pin:en,
 * så nästa anslutning kör migrationen en gång.
 * inbox_link_contacts_undo, contacts_merge_undo, booking_delete_undo, quote_outbox.manual_send,
 * quote_outbox.guard_job och quote_outbox_manual_undo ingår i de filerna.
 *
 * Pin = hash(sha256, schema/sqlite.php . "\n" . schema/mysql.php).
 */
function jeab_pdo_schema_migration_pin(): string
{
    return '1b6b052004fe6a634451dbe64dd2de11ebcdcbe583fa12a24d71db5b1e98af92';
}

/** Bumpas när schemat ändras så nästa anslutning kör migrationen en gång. */
function jeab_pdo_schema_id(string $driver): string
{
    return '20261023a-' . substr(jeab_pdo_schema_migration_pin(), 0, 12) . '-' . ($driver === 'mysql' ? 'mysql' : 'sqlite');
}

function jeab_pdo_schema_mark_path(string $driver): string
{
    return jeab_db_slot_dir() . '/schema-' . jeab_pdo_schema_id($driver) . '.ok';
}

function jeab_pdo_schema_current(string $driver): bool
{
    $path = jeab_pdo_schema_mark_path($driver);
    if (!is_file($path)) {
        return false;
    }
    $raw = @file_get_contents($path);

    return is_string($raw) && trim($raw) === jeab_pdo_schema_id($driver);
}

/**
 * Sqlite-filen finns inte än. Bara en ren flaggläsning (auth_epoch) får
 * återvända utan att skapa den. jeab_data_pdo skapar filen när databasen behövs.
 */
function jeab_sqlite_file_missing(): bool
{
    if (!function_exists('jeab_driver') || jeab_driver() !== 'sqlite') {
        return false;
    }
    if (!function_exists('jeab_config')) {
        return false;
    }
    $cfg = jeab_config();
    $path = (string) ($cfg['db']['sqlite_path'] ?? '');

    return $path !== '' && !is_file($path);
}

/** Stämpeln räcker inte om filen eller databasen är ny och settings saknas. */
function jeab_pdo_schema_applied(PDO $pdo, string $driver): bool
{
    if (!jeab_pdo_schema_current($driver)) {
        return false;
    }
    try {
        $pdo->query('SELECT 1 FROM settings LIMIT 1');

        return true;
    } catch (Throwable) {
        return false;
    }
}

function jeab_pdo_schema_mark(string $driver): void
{
    $path = jeab_pdo_schema_mark_path($driver);
    @file_put_contents($path, jeab_pdo_schema_id($driver), LOCK_EX);
}

/** PDO som fortfarande hålls av store eller inkorgen. En andra sqlite-anslutning låser filen. */
function jeab_pdo_sqlite_held(): ?PDO
{
    $candidates = [];
    $store = $GLOBALS['_JEAB_ACTIVE_STORE'] ?? null;
    if (class_exists('JeabPdoStore', false) && $store instanceof JeabPdoStore && $store->hasPdo()) {
        $candidates[] = $store->pdo();
    }
    if (class_exists('JeabPdoInkorgStore', false)) {
        foreach (['_JEAB_INKORG_SQL_LIVE', '_JEAB_INKORG_STORE'] as $key) {
            $ink = $GLOBALS[$key] ?? null;
            if ($ink instanceof JeabPdoInkorgStore && $ink->hasPdo()) {
                $candidates[] = $ink->pdo();
            }
        }
    }
    $side = $GLOBALS['_JEAB_SIDE_SQLITE'] ?? null;
    if ($side instanceof PDO) {
        $candidates[] = $side;
    }
    foreach ($candidates as $pdo) {
        try {
            if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                return $pdo;
            }
        } catch (Throwable) {
            continue;
        }
    }

    return null;
}

/**
 * Sekunder för TCP-anslutningen. null = drivrutinens eget tak (CLI och tick).
 * Ett vanligt klick väntar högst 1 sekund. Interaktiv header gör detsamma.
 * Utan tak kan en tyst värd (SQLSTATE 2002) hålla sidan i cirka 30 sekunder.
 */
function jeab_pdo_connect_timeout(): ?int
{
    if (!empty($GLOBALS['_JEAB_TICK']) || !empty($GLOBALS['_JEAB_DATA_MIGRATE'])) {
        return null;
    }
    $sapi = (string) ($GLOBALS['_JEAB_SAPI'] ?? PHP_SAPI);
    if ($sapi === 'cli' || $sapi === 'phpdbg') {
        return null;
    }
    $script = basename(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? '')));
    if (in_array($script, ['tick.php', 'sms-cron.php', 'sms-tick.php'], true)) {
        return null;
    }
    // Ett klick, även det första efter session_start, väntar högst 1 sekund på TCP.
    return 1;
}

/**
 * CREATE/ALTER hör hemma i CLI, tick och migrate. En sidvisning ska inte
 * köra hela schemat bara för att stämpelfilen saknas: det är det som tar
 * tiotals sekunder på första träffen efter en deploy.
 */
function jeab_pdo_schema_on_request(): bool
{
    if (!empty($GLOBALS['_JEAB_TICK']) || !empty($GLOBALS['_JEAB_DATA_MIGRATE']) || !empty($GLOBALS['_JEAB_SCHEMA_DDL'])) {
        return true;
    }
    $sapi = (string) ($GLOBALS['_JEAB_SAPI'] ?? PHP_SAPI);
    if ($sapi === 'cli' || $sapi === 'phpdbg') {
        return true;
    }
    $script = basename(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? '')));

    return in_array($script, ['tick.php', 'sms-cron.php', 'sms-tick.php'], true);
}

function jeab_pdo_connect_sqlite(): PDO
{
    $path = (string) jeab_config()['db']['sqlite_path'];
    $key = 'sqlite:' . $path;
    $cached = jeab_pdo_pool_get($key);
    if ($cached instanceof PDO) {
        return $cached;
    }
    if (empty($GLOBALS['_JEAB_PDO_REPLACING'])) {
        $held = jeab_pdo_sqlite_held();
        if ($held instanceof PDO) {
            $GLOBALS['_JEAB_PDO_POOL'][$key] = $held;

            return $held;
        }
    }
    if (!jeab_pdo_has('sqlite')) {
        throw new RuntimeException('Kunde inte spara i data/. På Loopia: chmod 775 data (eller 777 om 775 inte räcker).');
    }
    if (jeab_store_path_is_public_data($path)) {
        throw new RuntimeException('sqlite skrivs inte under publik data/');
    }
    $private = rtrim(str_replace('\\', '/', jeab_data_dir()), '/');
    $norm = str_replace('\\', '/', $path);
    if (str_starts_with($norm, $private . '/') && !jeab_private_data_writable()) {
        throw new RuntimeException('privat katalog saknas');
    }
    jeab_data_dir_ready($path);
    jeab_pdo_bg_guard();

    $pdo = jeab_pdo_open_once('sqlite:' . $path, null, null, jeab_pdo_base_options());
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $migrated = false;
    if (!jeab_pdo_schema_applied($pdo, 'sqlite') && jeab_pdo_schema_on_request()) {
        $GLOBALS['_JEAB_SCHEMA_MIGRATING'] = 1;
        try {
            jeab_migrate_sqlite($pdo);
            jeab_pdo_schema_mark('sqlite');
            $migrated = true;
        } finally {
            $GLOBALS['_JEAB_SCHEMA_MIGRATING'] = 0;
        }
    }
    $pdo = jeab_pdo_pool_put($key, $pdo);
    if ($migrated) {
        jeab_pdo_resolve_known_busy($pdo, 'sqlite');
    }
    if (empty($GLOBALS['_JEAB_WARM'])) {
        jeab_booking_week_query_boot($pdo, 'sqlite');
    }

    return $pdo;
}

function jeab_pdo_connect_mysql(): PDO
{
    $m = jeab_config()['db']['mysql'];
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $m['host'],
        $m['name'],
        jeab_pdo_mysql_charset($m['charset'] ?? null)
    );
    $key = 'mysql:' . $dsn . '|' . (string) $m['user'];
    $cached = jeab_pdo_pool_get($key);
    if ($cached instanceof PDO) {
        return $cached;
    }
    foreach ($GLOBALS['_JEAB_PDO_POOL'] ?? [] as $existing) {
        if (!$existing instanceof PDO) {
            continue;
        }
        try {
            if ((string) $existing->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                return $existing;
            }
        } catch (Throwable) {
        }
    }
    if (!empty($GLOBALS['_JEAB_PDO_CONNECT_REFUSED'])) {
        throw new JeabDbBusyException(jeab_pdo_busy_message());
    }
    if (!jeab_pdo_has('mysql')) {
        throw new RuntimeException('Kunde inte spara i data/. På Loopia: chmod 775 data (eller 777 om 775 inte räcker).');
    }
    $opts = jeab_pdo_base_options() + [
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
        $opts[PDO::MYSQL_ATTR_INIT_COMMAND] = jeab_pdo_mysql_names_sql();
    }
    $connectTimeout = jeab_pdo_connect_timeout();
    if ($connectTimeout !== null && defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
        $opts[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = $connectTimeout;
    }
    jeab_pdo_bg_guard();
    $connectFrom = microtime(true);
    try {
        try {
            $pdo = jeab_pdo_open_once($dsn, (string) $m['user'], (string) $m['pass'], $opts);
        } catch (PDOException $e) {
            if (jeab_pdo_is_connect_timeout($e) && jeab_pdo_get_may_retry_connect()) {
                $GLOBALS['_JEAB_PDO_CONNECT_RETRIED'] = 1;
                $pdo = jeab_pdo_open_once($dsn, (string) $m['user'], (string) $m['pass'], $opts);
            } else {
                throw $e;
            }
        }
    } catch (PDOException $e) {
        if (function_exists('jeab_timing_add')) {
            jeab_timing_add('db_connect', microtime(true) - $connectFrom);
        }
        if (jeab_pdo_is_connect_refused($e) || jeab_pdo_is_max_user_connections($e) || jeab_pdo_is_connect_timeout($e)) {
            $GLOBALS['_JEAB_PDO_CONNECT_REFUSED'] = 1;
            throw new JeabDbBusyException(jeab_pdo_busy_message());
        }
        throw $e;
    }
    if (function_exists('jeab_timing_add')) {
        jeab_timing_add('db_connect', microtime(true) - $connectFrom);
    }
    if (!defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
        jeab_pdo_mysql_ensure_utf8mb4($pdo);
    }
    $migrated = false;
    if (!jeab_pdo_schema_applied($pdo, 'mysql') && jeab_pdo_schema_on_request()) {
        $GLOBALS['_JEAB_SCHEMA_MIGRATING'] = 1;
        try {
            jeab_migrate_mysql($pdo);
            jeab_pdo_schema_mark('mysql');
            $migrated = true;
        } finally {
            $GLOBALS['_JEAB_SCHEMA_MIGRATING'] = 0;
        }
    }
    $pdo = jeab_pdo_pool_put($key, $pdo);
    if ($migrated) {
        jeab_pdo_resolve_known_busy($pdo, 'mysql');
    }
    if (empty($GLOBALS['_JEAB_WARM'])) {
        jeab_booking_week_query_boot($pdo, 'mysql');
    }

    return $pdo;
}

/**
 * Tabeller där store.php skapar rader utan id och läser lastInsertId().
 *
 * @return list<string>
 */
function jeab_sql_autoincrement_tables(): array
{
    return ['reminders', 'bookings', 'customers', 'reminder_lists'];
}

function jeab_sql_ident_ok(string $name): bool
{
    return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
}

/** @param array<string, bool> $set */
function jeab_sql_id_generates_map(): WeakMap
{
    static $map;
    $map ??= new WeakMap();

    return $map;
}

function jeab_sql_id_generates_cache_set(PDO $pdo, string $table, bool $generates): void
{
    $map = jeab_sql_id_generates_map();
    $bag = isset($map[$pdo]) && is_array($map[$pdo]) ? $map[$pdo] : [];
    $bag[$table] = $generates;
    $map[$pdo] = $bag;
}

/**
 * Sant när en INSERT utan id får ett nytt id.
 * MySQL: kolumnen id har AUTO_INCREMENT.
 * SQLite: bara INTEGER PRIMARY KEY (INT PRIMARY KEY gör det inte).
 */
function jeab_sql_id_generates(PDO $pdo, string $table): bool
{
    if (!jeab_sql_ident_ok($table)) {
        return false;
    }
    $map = jeab_sql_id_generates_map();
    if (isset($map[$pdo]) && is_array($map[$pdo]) && array_key_exists($table, $map[$pdo])) {
        return (bool) $map[$pdo][$table];
    }
    $generates = false;
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . "` LIKE 'id'");
            $col = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            $extra = is_array($col) ? strtolower((string) ($col['Extra'] ?? '')) : '';
            $generates = str_contains($extra, 'auto_increment');
        } else {
            $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
            foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $col) {
                if (!is_array($col) || (string) ($col['name'] ?? '') !== 'id') {
                    continue;
                }
                $type = strtoupper(trim((string) ($col['type'] ?? '')));
                $generates = (int) ($col['pk'] ?? 0) > 0 && str_starts_with($type, 'INTEGER');
                break;
            }
        }
    } catch (Throwable) {
        $generates = false;
    }
    jeab_sql_id_generates_cache_set($pdo, $table, $generates);

    return $generates;
}

/**
 * ALTER-satser som lägger AUTO_INCREMENT utan att röra befintliga id.
 * $next ska vara MAX(id)+1 (minst 1).
 *
 * @return array{modify:string,seed:string}
 */
function jeab_sql_autoincrement_sql(string $table, string $type, string $nullSql, int $next): array
{
    if (!jeab_sql_ident_ok($table) || !preg_match('/^[a-z0-9() ]+$/i', $type)) {
        throw new InvalidArgumentException('Ogiltig kolumntyp för AUTO_INCREMENT.');
    }
    if ($nullSql !== 'NULL' && $nullSql !== 'NOT NULL') {
        throw new InvalidArgumentException('Ogiltig null-regel för AUTO_INCREMENT.');
    }
    if ($next < 1) {
        $next = 1;
    }

    return [
        'modify' => 'ALTER TABLE `' . $table . '` MODIFY `id` ' . $type . ' ' . $nullSql . ' AUTO_INCREMENT',
        'seed' => 'ALTER TABLE `' . $table . '` AUTO_INCREMENT = ' . $next,
    ];
}

/**
 * Lägger AUTO_INCREMENT på id om den saknas, och sätter räknaren till MAX(id)+1.
 * Idempotent. Raderar inga rader och byter inga id. Misslyckad ALTER (t.ex. främmande nyckel)
 * lämnar tabellen orörd och returnerar false så insert kan sätta id själv.
 */
function jeab_sql_ensure_autoincrement(PDO $pdo, ?string $only = null): bool
{
    try {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return false;
        }
    } catch (Throwable) {
        return false;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return false;
    }
    $tables = $only !== null ? [$only] : jeab_sql_autoincrement_tables();
    $ok = true;
    foreach ($tables as $table) {
        if (!in_array($table, jeab_sql_autoincrement_tables(), true)) {
            continue;
        }
        if (!jeab_sql_ensure_autoincrement_table($pdo, $table)) {
            $ok = false;
        }
    }

    return $ok;
}

function jeab_sql_autoincrement_gave_up(string $table, ?bool $set = null): bool
{
    static $gave = [];
    if ($set === true) {
        $gave[$table] = true;
    }

    return !empty($gave[$table]);
}

function jeab_sql_ensure_autoincrement_table(PDO $pdo, string $table): bool
{
    if (jeab_sql_autoincrement_gave_up($table)) {
        return false;
    }
    if (jeab_sql_id_generates($pdo, $table)) {
        try {
            $maxStmt = $pdo->query('SELECT COALESCE(MAX(id), 0) FROM `' . $table . '`');
            $max = (int) ($maxStmt ? $maxStmt->fetchColumn() : 0);
            $next = $max < 0 ? 1 : $max + 1;
            if ($next < 1) {
                $next = 1;
            }
            $current = jeab_sql_mysql_autoincrement($pdo, $table);
            // Sänk inte räknaren. MAX(id)+1 återanvänder ett raderat högsta id.
            if ($current !== null && $current < $next) {
                $pdo->exec('ALTER TABLE `' . $table . '` AUTO_INCREMENT = ' . $next);
            }
        } catch (Throwable $e) {
            error_log('autoincrement seed ' . $table . ' ' . $e::class);
        }

        return true;
    }
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . "` LIKE 'id'");
        $col = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!is_array($col)) {
            return false;
        }
        $type = strtolower(trim((string) ($col['Type'] ?? '')));
        $nullSql = strtoupper((string) ($col['Null'] ?? 'NO')) === 'YES' ? 'NULL' : 'NOT NULL';
        $maxStmt = $pdo->query('SELECT COALESCE(MAX(id), 0) FROM `' . $table . '`');
        $max = (int) ($maxStmt ? $maxStmt->fetchColumn() : 0);
        $next = $max < 0 ? 1 : $max + 1;
        $sql = jeab_sql_autoincrement_sql($table, $type, $nullSql, $next);
        $pdo->exec($sql['modify']);
        jeab_sql_id_generates_cache_set($pdo, $table, true);
        try {
            $pdo->exec($sql['seed']);
        } catch (Throwable $e) {
            error_log('autoincrement seed ' . $table . ' ' . $e::class);
        }

        return true;
    } catch (Throwable $e) {
        error_log('autoincrement ' . $table . ' ' . $e::class);
        jeab_sql_id_generates_cache_set($pdo, $table, false);
        jeab_sql_autoincrement_gave_up($table, true);

        return false;
    }
}

/**
 * Räknare som bara ökar. MAX(id)+1 återanvänder ett raderat högsta id.
 * Tabellen skapas före transaktionen: CREATE i MySQL committar implicit.
 */
function jeab_sql_id_seq_ensure(PDO $pdo): void
{
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    $map = jeab_sql_id_generates_map();
    $bag = isset($map[$pdo]) && is_array($map[$pdo]) ? $map[$pdo] : [];
    if (!empty($bag['jeab_id_seq'])) {
        return;
    }
    $driver = 'sqlite';
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    } catch (Throwable) {
        $driver = 'sqlite';
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS jeab_id_seq (
                name VARCHAR(64) NOT NULL,
                next_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS jeab_id_seq (
                name TEXT NOT NULL PRIMARY KEY,
                next_id INTEGER NOT NULL
            )'
        );
    }
    $bag['jeab_id_seq'] = true;
    $map[$pdo] = $bag;
}

/** Nuvarande AUTO_INCREMENT, eller null när den inte går att läsa. Sänks aldrig här. */
function jeab_sql_mysql_autoincrement(PDO $pdo, string $table): ?int
{
    if (!jeab_sql_ident_ok($table)) {
        return null;
    }
    try {
        $stmt = $pdo->query(
            'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $pdo->quote($table)
        );
        $value = $stmt ? $stmt->fetchColumn() : false;
    } catch (Throwable) {
        return null;
    }
    if ($value === false || $value === null || $value === '') {
        return null;
    }
    $current = (int) $value;

    return $current > 0 ? $current : null;
}

/**
 * Nästa id. Anropas inuti en transaktion. Räknaren i jeab_id_seq går bara upp,
 * så ett raderat id inte kommer tillbaka. Befintliga rader uppdateras inte.
 */
function jeab_sql_next_id_locked(PDO $pdo, string $table): int
{
    if (!jeab_sql_ident_ok($table)) {
        throw new InvalidArgumentException('Ogiltig tabell.');
    }
    $driver = 'sqlite';
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    } catch (Throwable) {
        $driver = 'sqlite';
    }
    $maxStmt = $pdo->query('SELECT COALESCE(MAX(id), 0) FROM ' . $table);
    $max = (int) ($maxStmt ? $maxStmt->fetchColumn() : 0);
    $floor = $max < 0 ? 1 : $max + 1;
    if ($floor < 1) {
        $floor = 1;
    }
    if ($driver === 'mysql') {
        $pdo->prepare('INSERT INTO jeab_id_seq (name, next_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE next_id = next_id')
            ->execute([$table, $floor]);
        $stmt = $pdo->prepare('SELECT next_id FROM jeab_id_seq WHERE name = ? FOR UPDATE');
    } else {
        $pdo->prepare('INSERT INTO jeab_id_seq (name, next_id) VALUES (?, ?) ON CONFLICT(name) DO NOTHING')
            ->execute([$table, $floor]);
        $stmt = $pdo->prepare('SELECT next_id FROM jeab_id_seq WHERE name = ?');
    }
    $stmt->execute([$table]);
    $next = (int) $stmt->fetchColumn();
    $id = max($next, $floor, 1);
    $pdo->prepare('UPDATE jeab_id_seq SET next_id = ? WHERE name = ?')->execute([$id + 1, $table]);

    return $id;
}

function jeab_sql_insert_missing_id(PDOException $e): bool
{
    $msg = $e->getMessage();

    return str_contains($msg, 'NOT NULL constraint failed')
        || str_contains($msg, "doesn't have a default value")
        || str_contains($msg, 'does not have a default value');
}

/**
 * Flyttar en rad som just skrevs med id 0 till MAX(id)+1.
 * En äldre rad med id 0 rörs inte: den här anropas bara när inserten skapade nollan.
 */
function jeab_sql_reassign_zero_id(PDO $pdo, string $table): int
{
    jeab_sql_id_seq_ensure($pdo);

    return jeab_pdo_transaction($pdo, static function (PDO $tx) use ($table): int {
        $id = jeab_sql_next_id_locked($tx, $table);
        $stmt = $tx->prepare('UPDATE ' . $table . ' SET id = ? WHERE id = 0');
        $stmt->execute([$id]);
        if ($stmt->rowCount() < 1 || $id <= 0) {
            throw new RuntimeException($table . ': insert gav inget id.');
        }

        return $id;
    });
}

/** Sant när kolumnen finns. Ett ja cachas per anslutning. Ett nej läses om. */
function jeab_sql_has_column(PDO $pdo, string $table, string $column): bool
{
    if (!jeab_sql_ident_ok($table) || !jeab_sql_ident_ok($column)) {
        return false;
    }
    static $yes = [];
    $key = spl_object_id($pdo) . "\0" . $table . "\0" . $column;
    if (isset($yes[$key])) {
        return true;
    }
    $found = false;
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
            foreach ($stmt->fetchAll() as $col) {
                if (is_array($col) && (string) ($col['Field'] ?? '') === $column) {
                    $found = true;
                    break;
                }
            }
        } else {
            foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $col) {
                if (is_array($col) && (string) ($col['name'] ?? '') === $column) {
                    $found = true;
                    break;
                }
            }
        }
    } catch (Throwable) {
        return false;
    }
    if ($found) {
        $yes[$key] = true;
    }

    return $found;
}

/**
 * Kundkopior som contacts.drop_customer_fields kan ta bort. Kontakten via contact_id är källan.
 * Koden fungerar både med och utan kolumnerna. solkampanj_recipients är en egen lista
 * utanför kontakter och ingår inte.
 *
 * @return list<string>
 */
function jeab_copy_columns(string $table): array
{
    return match ($table) {
        'reminders' => ['customer_name', 'email', 'phone', 'address', 'ort', 'postal'],
        'bookings' => ['customer_name', 'email', 'phone', 'address'],
        'inkorg_list' => ['customer_email'],
        default => [],
    };
}

/** @var array<string, bool> */
$GLOBALS['_JEAB_COPY_COLS'] = $GLOBALS['_JEAB_COPY_COLS'] ?? [];

function jeab_copy_columns_forget(): void
{
    $GLOBALS['_JEAB_COPY_COLS'] = [];
    unset($GLOBALS['_JEAB_COPY_DROPPED']);
}

/** Inställningen som contacts.drop_customer_fields sätter. Schemat lägger då inte tillbaka kopiekolumnerna. */
function jeab_copy_columns_marker_key(): string
{
    return 'contacts_drop_customer_fields';
}

/**
 * Publik markör: tabell => tid. solkampanj_recipients ingår inte. En gammal post
 * för den tabellen får ligga kvar i settings och stör inte kontrollen.
 *
 * @return array<string, string>
 */
function jeab_copy_columns_marker(PDO $pdo): array
{
    $key = spl_object_id($pdo);
    if (isset($GLOBALS['_JEAB_COPY_DROPPED'][$key]) && is_array($GLOBALS['_JEAB_COPY_DROPPED'][$key])) {
        return $GLOBALS['_JEAB_COPY_DROPPED'][$key];
    }
    $out = [];
    try {
        $col = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? '`key`' : 'key';
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE ' . $col . ' = ?');
        $stmt->execute([jeab_copy_columns_marker_key()]);
        $raw = $stmt->fetchColumn();
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            foreach ($decoded as $table => $at) {
                if (!is_string($table) || $table === 'solkampanj_recipients' || jeab_copy_columns($table) === []) {
                    continue;
                }
                $out[$table] = (string) $at;
            }
        }
    } catch (Throwable) {
        $out = [];
    }
    $GLOBALS['_JEAB_COPY_DROPPED'][$key] = $out;

    return $out;
}

function jeab_copy_columns_dropped(PDO $pdo, string $table): bool
{
    return isset(jeab_copy_columns_marker($pdo)[$table]);
}

/** Finns kopiekolumnen kvar? Andra kolumner svarar alltid ja. Cachas per anslutning, både ja och nej. */
function jeab_copy_column_present(PDO $pdo, string $table, string $column): bool
{
    if (!in_array($column, jeab_copy_columns($table), true)) {
        return true;
    }
    $key = spl_object_id($pdo) . "\0" . $table . "\0" . $column;
    $cache = is_array($GLOBALS['_JEAB_COPY_COLS'] ?? null) ? $GLOBALS['_JEAB_COPY_COLS'] : [];
    if (array_key_exists($key, $cache)) {
        return (bool) $cache[$key];
    }
    $found = false;
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $rows = $driver === 'mysql'
            ? $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC)
            : $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
        foreach (is_array($rows) ? $rows : [] as $col) {
            $name = is_array($col) ? (string) ($col['Field'] ?? $col['name'] ?? '') : '';
            $cache[spl_object_id($pdo) . "\0" . $table . "\0" . $name] = true;
            if ($name === $column) {
                $found = true;
            }
        }
    } catch (Throwable) {
        return false;
    }
    foreach (jeab_copy_columns($table) as $one) {
        $k = spl_object_id($pdo) . "\0" . $table . "\0" . $one;
        if (!array_key_exists($k, $cache)) {
            $cache[$k] = false;
        }
    }
    $GLOBALS['_JEAB_COPY_COLS'] = $cache;

    return $found;
}

/**
 * SELECT-lista där en borttagen kopiekolumn blir NULL AS kolumn.
 *
 * @param list<string> $columns
 */
function jeab_copy_select(PDO $pdo, string $table, array $columns, string $prefix = ''): string
{
    $out = [];
    foreach ($columns as $col) {
        $out[] = jeab_copy_column_present($pdo, $table, $col) ? $prefix . $col : 'NULL AS ' . $col;
    }

    return implode(', ', $out);
}

/**
 * Tar bort borttagna kopiekolumner ur en INSERT/UPDATE.
 *
 * @param list<string> $columns
 * @param list<mixed> $values
 * @return array{0:list<string>,1:list<mixed>}
 */
function jeab_copy_filter_write(PDO $pdo, string $table, array $columns, array $values): array
{
    $c = [];
    $v = [];
    foreach ($columns as $i => $col) {
        if (jeab_copy_column_present($pdo, $table, $col)) {
            $c[] = $col;
            $v[] = $values[$i] ?? null;
        }
    }

    return [$c, $v];
}

/**
 * INSERT som alltid lämnar ett id > 0.
 * INTEGER PRIMARY KEY och AUTO_INCREMENT låter databasen sätta id via lastInsertId().
 * Utan AUTO_INCREMENT sätts id från jeab_id_seq, som inte går bakåt.
 *
 * @param list<string> $columns
 * @param list<mixed> $params
 */
function jeab_sql_insert_id(PDO $pdo, string $table, array $columns, array $params): int
{
    if (!in_array($table, jeab_sql_autoincrement_tables(), true) || !jeab_sql_ident_ok($table)) {
        throw new InvalidArgumentException('Ogiltig tabell.');
    }
    foreach ($columns as $column) {
        if (!jeab_sql_ident_ok((string) $column) || (string) $column === 'id') {
            throw new InvalidArgumentException('Ogiltig kolumn.');
        }
    }
    if (count($columns) !== count($params)) {
        throw new InvalidArgumentException('Insert saknar värden.');
    }
    if (!jeab_sql_id_generates($pdo, $table)) {
        jeab_sql_ensure_autoincrement($pdo, $table);
    }
    if (jeab_sql_id_generates($pdo, $table)) {
        $marks = implode(', ', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . $marks . ')';
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $id = (int) $pdo->lastInsertId();
            if ($id > 0) {
                return $id;
            }
            // Raden är redan skriven. En andra insert skulle bli en kopia,
            // och en UPDATE av id 0 skulle ändra en äldre rad.
            throw new RuntimeException($table . ': insert gav inget id.');
        } catch (PDOException $e) {
            if (!jeab_sql_insert_missing_id($e)) {
                throw $e;
            }
        }
    }

    return jeab_sql_insert_explicit_id($pdo, $table, $columns, $params);
}

/**
 * @param list<string> $columns
 * @param list<mixed> $params
 */
function jeab_sql_insert_explicit_id(PDO $pdo, string $table, array $columns, array $params): int
{
    jeab_sql_id_seq_ensure($pdo);
    try {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
    } catch (Throwable) {
    }
    $last = null;
    for ($try = 0; $try < 5; $try++) {
        try {
            return jeab_pdo_transaction($pdo, static function (PDO $tx) use ($table, $columns, $params): int {
                $id = jeab_sql_next_id_locked($tx, $table);
                $marks = implode(', ', array_fill(0, count($columns) + 1, '?'));
                $sql = 'INSERT INTO ' . $table . ' (id, ' . implode(', ', $columns) . ') VALUES (' . $marks . ')';
                $tx->prepare($sql)->execute(array_merge([$id], $params));
                $check = $tx->prepare('SELECT id FROM ' . $table . ' WHERE id = ?');
                $check->execute([$id]);
                $got = $check->fetchColumn();
                if ((int) $got !== $id || $id <= 0) {
                    throw new RuntimeException($table . ': insert gav inget id.');
                }

                return $id;
            });
        } catch (PDOException $e) {
            $last = $e;
            $state = (string) ($e->errorInfo[0] ?? '');
            $msg = $e->getMessage();
            $duplicate = $state === '23000'
                || str_contains($msg, 'UNIQUE constraint failed')
                || str_contains($msg, 'Duplicate entry');
            $busy = str_contains($msg, 'database is locked')
                || str_contains($msg, 'database schema is locked')
                || str_contains($msg, 'Lock wait timeout')
                || str_contains($msg, 'Deadlock');
            if (!$duplicate && !$busy) {
                throw $e;
            }
        }
    }
    throw new RuntimeException($table . ': insert gav inget id.', 0, $last);
}

function jeab_migrate_sqlite(PDO $pdo): void
{
    $GLOBALS['_JEAB_MIGRATE_RUNS'] = (int) ($GLOBALS['_JEAB_MIGRATE_RUNS'] ?? 0) + 1;
    $sql = require JEAB_ROOT . '/schema/sqlite.php';
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException('Saknar schema/sqlite.php');
    }
    $pdo->exec($sql);
    jeab_ensure_booking_contact_columns($pdo, 'sqlite');
    jeab_ensure_reminder_contact_columns($pdo, 'sqlite');
    jeab_ensure_assignees_columns($pdo, 'sqlite');
    jeab_ensure_reminder_apple_columns($pdo, 'sqlite');
    jeab_ensure_booking_company_column($pdo, 'sqlite');
    jeab_ensure_reminder_company_columns($pdo, 'sqlite');
    jeab_ensure_reminder_tap_columns($pdo, 'sqlite');
    jeab_ensure_reminder_ort_column($pdo, 'sqlite');
    jeab_ensure_reminder_postal_column($pdo, 'sqlite');
    jeab_ensure_contact_id_columns($pdo, 'sqlite');
    jeab_ensure_list_indexes($pdo, 'sqlite');
    jeab_ensure_reminder_thread_column($pdo, 'sqlite');
    jeab_ensure_json_doc_tables($pdo, 'sqlite');
    jeab_ensure_inbox_link_undo_table($pdo, 'sqlite');
    jeab_ensure_contacts_merge_undo_table($pdo, 'sqlite');
    jeab_ensure_booking_delete_undo($pdo, 'sqlite');
    jeab_ensure_contact_email_audit($pdo, 'sqlite');
    jeab_ensure_order_owner_audit($pdo, 'sqlite');
    jeab_ensure_order_mobil_audit($pdo, 'sqlite');
    jeab_ensure_reminder_admin_audit($pdo, 'sqlite');
    jeab_ensure_notis_switches($pdo, 'sqlite');
    jeab_ensure_notis_sends($pdo, 'sqlite');
    jeab_ensure_notis_history($pdo, 'sqlite');
    jeab_ensure_inkorg_tables($pdo, 'sqlite');
    jeab_ensure_contacts_tables($pdo, 'sqlite');
    jeab_ensure_contact_search_prefix($pdo, 'sqlite');
    jeab_ensure_data_jobs($pdo, 'sqlite');
    jeab_ensure_fortnox_order_audit($pdo, 'sqlite');
    if (function_exists('jeab_outlook_ensure')) {
        jeab_outlook_ensure($pdo);
    }
    jeab_ensure_inbox_reclassify_audit($pdo, 'sqlite');
    jeab_ensure_inbox_klassning_log($pdo, 'sqlite');
    jeab_ensure_inbox_misclass($pdo, 'sqlite');
    jeab_ensure_inbox_service_skip($pdo, 'sqlite');
    jeab_ensure_inbox_sent($pdo, 'sqlite');
    if (function_exists('jeab_send_once_install')) {
        jeab_send_once_install($pdo);
    }
    jeab_ensure_inbox_thread_override($pdo, 'sqlite');
    jeab_ensure_malmo_hs_cursor($pdo, 'sqlite');
    jeab_ensure_graph_hook_queue($pdo, 'sqlite');
    jeab_ensure_quote_outbox($pdo, 'sqlite');
    if (function_exists('jeab_ensure_lead_mail_tables')) {
        jeab_ensure_lead_mail_tables($pdo, 'sqlite');
    }
    jeab_ensure_reminder_list_index($pdo, 'sqlite');
    if (function_exists('jeab_send_guard_legacy_claims_backfill')) {
        $prevSchemaDdl = $GLOBALS['_JEAB_SCHEMA_DDL'] ?? null;
        $GLOBALS['_JEAB_SCHEMA_DDL'] = true;
        try {
            jeab_send_guard_legacy_claims_backfill($pdo);
        } finally {
            if ($prevSchemaDdl === null) {
                unset($GLOBALS['_JEAB_SCHEMA_DDL']);
            } else {
                $GLOBALS['_JEAB_SCHEMA_DDL'] = $prevSchemaDdl;
            }
        }
    }
    jeab_booking_week_query_migrate($pdo, 'sqlite');
    jeab_booking_company_canon($pdo, 'sqlite');
}

function jeab_migrate_mysql(PDO $pdo): void
{
    $GLOBALS['_JEAB_MIGRATE_RUNS'] = (int) ($GLOBALS['_JEAB_MIGRATE_RUNS'] ?? 0) + 1;
    $tables = $pdo->query("SHOW TABLES LIKE 'reminders'")->fetchAll();
    if (!$tables) {
        $sql = require JEAB_ROOT . '/schema/mysql.php';
        if (!is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('Saknar schema/mysql.php');
        }
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            // Inledande "--"-rader hör inte till satsen. Annars hoppas CREATE över
            // när schemat börjar med en kommentar. Tomrader emellan tas med så
            // nästa kommentar också räknas som inledande.
            $lines = preg_split("/\r\n|\n|\r/", $stmt);
            if (!is_array($lines)) {
                continue;
            }
            while ($lines !== []) {
                $head = ltrim((string) $lines[0]);
                if ($head !== '' && !str_starts_with($head, '--')) {
                    break;
                }
                array_shift($lines);
            }
            $stmt = trim(implode("\n", $lines));
            if ($stmt === '' || str_starts_with($stmt, '--')) {
                continue;
            }
            $pdo->exec($stmt);
        }
    }
    jeab_ensure_booking_contact_columns($pdo, 'mysql');
    jeab_ensure_reminder_contact_columns($pdo, 'mysql');
    jeab_ensure_assignees_columns($pdo, 'mysql');
    jeab_ensure_reminder_apple_columns($pdo, 'mysql');
    jeab_ensure_booking_company_column($pdo, 'mysql');
    jeab_ensure_booking_job_type_width($pdo, 'mysql');
    jeab_ensure_reminder_company_columns($pdo, 'mysql');
    jeab_ensure_reminder_tap_columns($pdo, 'mysql');
    jeab_ensure_reminder_ort_column($pdo, 'mysql');
    jeab_ensure_reminder_postal_column($pdo, 'mysql');
    jeab_ensure_contact_id_columns($pdo, 'mysql');
    jeab_ensure_list_indexes($pdo, 'mysql');
    jeab_ensure_reminder_thread_column($pdo, 'mysql');
    jeab_ensure_json_doc_tables($pdo, 'mysql');
    jeab_ensure_inbox_link_undo_table($pdo, 'mysql');
    jeab_ensure_contacts_merge_undo_table($pdo, 'mysql');
    jeab_ensure_booking_delete_undo($pdo, 'mysql');
    jeab_ensure_contact_email_audit($pdo, 'mysql');
    jeab_ensure_order_owner_audit($pdo, 'mysql');
    jeab_ensure_order_mobil_audit($pdo, 'mysql');
    jeab_ensure_reminder_admin_audit($pdo, 'mysql');
    jeab_ensure_notis_switches($pdo, 'mysql');
    jeab_ensure_notis_sends($pdo, 'mysql');
    jeab_ensure_notis_history($pdo, 'mysql');
    jeab_ensure_inkorg_tables($pdo, 'mysql');
    jeab_ensure_contacts_tables($pdo, 'mysql');
    jeab_ensure_contact_search_prefix($pdo, 'mysql');
    jeab_ensure_data_jobs($pdo, 'mysql');
    jeab_ensure_fortnox_order_audit($pdo, 'mysql');
    if (function_exists('jeab_outlook_ensure')) {
        jeab_outlook_ensure($pdo);
    }
    jeab_ensure_inbox_reclassify_audit($pdo, 'mysql');
    jeab_ensure_inbox_klassning_log($pdo, 'mysql');
    jeab_ensure_inbox_misclass($pdo, 'mysql');
    jeab_ensure_inbox_service_skip($pdo, 'mysql');
    jeab_ensure_inbox_sent($pdo, 'mysql');
    if (function_exists('jeab_send_once_install')) {
        jeab_send_once_install($pdo);
    }
    jeab_ensure_inbox_thread_override($pdo, 'mysql');
    jeab_ensure_malmo_hs_cursor($pdo, 'mysql');
    jeab_ensure_graph_hook_queue($pdo, 'mysql');
    jeab_ensure_quote_outbox($pdo, 'mysql');
    if (function_exists('jeab_ensure_lead_mail_tables')) {
        jeab_ensure_lead_mail_tables($pdo, 'mysql');
    }
    jeab_ensure_reminder_list_index($pdo, 'mysql');
    if (function_exists('jeab_send_guard_legacy_claims_backfill')) {
        $prevSchemaDdl = $GLOBALS['_JEAB_SCHEMA_DDL'] ?? null;
        $GLOBALS['_JEAB_SCHEMA_DDL'] = true;
        try {
            jeab_send_guard_legacy_claims_backfill($pdo);
        } finally {
            if ($prevSchemaDdl === null) {
                unset($GLOBALS['_JEAB_SCHEMA_DDL']);
            } else {
                $GLOBALS['_JEAB_SCHEMA_DDL'] = $prevSchemaDdl;
            }
        }
    }
    jeab_sql_ensure_autoincrement($pdo);
    jeab_booking_week_query_migrate($pdo, 'mysql');
    jeab_booking_company_canon($pdo, 'mysql');
}

function jeab_ensure_booking_contact_columns(PDO $pdo, string $driver): void
{
    $wanted = [
        'email' => $driver === 'mysql' ? 'VARCHAR(191) DEFAULT NULL' : 'TEXT',
        'phone' => $driver === 'mysql' ? 'VARCHAR(64) DEFAULT NULL' : 'TEXT',
        'address' => $driver === 'mysql' ? 'VARCHAR(255) DEFAULT NULL' : 'TEXT',
        'notes' => 'TEXT',
    ];
    jeab_ensure_table_columns($pdo, 'bookings', $wanted, $driver);
}

function jeab_ensure_reminder_contact_columns(PDO $pdo, string $driver): void
{
    $wanted = [
        'customer_name' => $driver === 'mysql' ? 'VARCHAR(191) DEFAULT NULL' : 'TEXT',
        'email' => $driver === 'mysql' ? 'VARCHAR(191) DEFAULT NULL' : 'TEXT',
        'phone' => $driver === 'mysql' ? 'VARCHAR(64) DEFAULT NULL' : 'TEXT',
        'address' => $driver === 'mysql' ? 'VARCHAR(255) DEFAULT NULL' : 'TEXT',
        'assignee' => $driver === 'mysql' ? 'VARCHAR(64) DEFAULT NULL' : 'TEXT',
    ];
    jeab_ensure_table_columns($pdo, 'reminders', $wanted, $driver);
}

function jeab_ensure_assignees_columns(PDO $pdo, string $driver): void
{
    jeab_ensure_table_columns($pdo, 'bookings', ['assignees' => 'TEXT'], $driver);
    jeab_ensure_table_columns($pdo, 'reminders', ['assignees' => 'TEXT'], $driver);
}

function jeab_ensure_booking_company_column(PDO $pdo, string $driver): void
{
    $type = $driver === 'mysql' ? 'VARCHAR(32) DEFAULT NULL' : 'TEXT';
    jeab_ensure_table_columns($pdo, 'bookings', ['company' => $type], $driver);
}

function jeab_ensure_booking_job_type_width(PDO $pdo, string $driver): void
{
    if ($driver !== 'mysql') {
        return;
    }
    if (function_exists('jeab_schema_probe_allowed') && !jeab_schema_probe_allowed()) {
        return;
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'job_type'");
    $col = $stmt ? $stmt->fetch() : false;
    if (!$col) {
        return;
    }
    $type = strtolower((string) ($col['Type'] ?? ''));
    if (preg_match('/varchar\((\d+)\)/', $type, $m) && (int) $m[1] >= 64) {
        return;
    }
    $pdo->exec('ALTER TABLE bookings MODIFY job_type VARCHAR(64) DEFAULT NULL');
}

function jeab_ensure_reminder_company_columns(PDO $pdo, string $driver): void
{
    $company = $driver === 'mysql' ? 'VARCHAR(32) DEFAULT NULL' : 'TEXT';
    $person = $driver === 'mysql' ? 'VARCHAR(64) DEFAULT NULL' : 'TEXT';
    jeab_ensure_table_columns($pdo, 'reminders', [
        'company' => $company,
        'person' => $person,
    ], $driver);
}

function jeab_ensure_reminder_thread_column(PDO $pdo, string $driver): void
{
    $type = $driver === 'mysql' ? 'VARCHAR(64) DEFAULT NULL' : 'TEXT';
    jeab_ensure_table_columns($pdo, 'reminders', ['thread_id' => $type], $driver);
    try {
        $have = jeab_existing_index_names($pdo, 'reminders', $driver);
    } catch (Throwable) {
        return;
    }
    if (!in_array('reminders_thread_id', $have, true)) {
        $sql = $driver === 'mysql'
            ? 'CREATE INDEX reminders_thread_id ON reminders (thread_id)'
            : 'CREATE INDEX IF NOT EXISTS reminders_thread_id ON reminders (thread_id)';
        try {
            jeab_list_index_create($pdo, $sql);
        } catch (Throwable) {
        }
    }
}

function jeab_ensure_contact_id_columns(PDO $pdo, string $driver): void
{
    $type = $driver === 'mysql' ? 'VARCHAR(32) DEFAULT NULL' : 'TEXT';
    jeab_ensure_table_columns($pdo, 'reminders', ['contact_id' => $type], $driver);
    jeab_ensure_table_columns($pdo, 'bookings', ['contact_id' => $type], $driver);
}

/**
 * Index för listor. En flagga i settings (list_indexes_v1) så sidan inte kör
 * SHOW INDEX varje request. Byt nyckel om $wanted ändras.
 * Hoppar över TEXT/BLOB och VARCHAR längre än 191
 * (utf8mb4 ryms under MySQL 5.6:s 767-byte-gräns: 191*4 = 764).
 */
function jeab_ensure_list_indexes(PDO $pdo, string $driver): void
{
    static $settled = null;
    $settled ??= new WeakMap();
    if (isset($settled[$pdo])) {
        return;
    }
    $key = 'list_indexes_v2';
    $flag = jeab_settings_value($pdo, $driver, $key);
    if ($flag === 'ok' || ($flag !== null && str_starts_with($flag, 'fail:'))) {
        $settled[$pdo] = true;
        return;
    }
    $wanted = [
        'reminders' => [
            'reminders_contact_id' => ['contact_id'],
            'reminders_company' => ['company'],
            'reminders_person' => ['person'],
            'reminders_assignee' => ['assignee'],
            'reminders_completed' => ['completed_at'],
            'reminders_company_due' => ['company', 'due_at'],
        ],
        'bookings' => [
            'bookings_contact_id' => ['contact_id'],
            'bookings_company' => ['company'],
            'bookings_company_starts' => ['company', 'starts_at'],
            'bookings_status_starts' => ['status', 'starts_at'],
            'bookings_job_type' => ['job_type'],
            'bookings_assignee' => ['assignee'],
        ],
    ];
    $failed = [];
    foreach ($wanted as $table => $indexes) {
        $have = jeab_existing_index_names($pdo, $table, $driver);
        foreach ($indexes as $name => $columns) {
            if (in_array($name, $have, true)) {
                continue;
            }
            $ok = true;
            foreach ($columns as $column) {
                if (!jeab_index_column_ok($pdo, $table, $column, $driver)) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) {
                continue;
            }
            $cols = implode(', ', $columns);
            $sql = $driver === 'mysql'
                ? 'CREATE INDEX ' . $name . ' ON ' . $table . ' (' . $cols . ')'
                : 'CREATE INDEX IF NOT EXISTS ' . $name . ' ON ' . $table . ' (' . $cols . ')';
            try {
                jeab_list_index_create($pdo, $sql);
            } catch (Throwable $e) {
                $message = $e->getMessage();
                if (stripos($message, 'Duplicate') !== false || stripos($message, 'already exists') !== false) {
                    continue;
                }
                $failed[] = $name . ': ' . $message;
            }
        }
    }
    if ($failed === []) {
        if (jeab_settings_put($pdo, $driver, $key, 'ok')) {
            $settled[$pdo] = true;
        }
        return;
    }
    $note = implode('; ', $failed);
    if (function_exists('jeab_log_app_error')) {
        jeab_log_app_error('db', 'CREATE INDEX misslyckades: ' . $note);
    }
    if (jeab_settings_put($pdo, $driver, $key, 'fail:' . $note)) {
        $settled[$pdo] = true;
    }
}

/**
 * MySQL saknar CREATE INDEX IF NOT EXISTS (fel 1064). SQLite har satsen.
 * $on är «tabell (kolumner)», redan kontrollerat av anroparen.
 */
function jeab_create_index_sql(string $driver, string $name, string $on): string
{
    if ($driver === 'mysql') {
        return 'CREATE INDEX ' . $name . ' ON ' . $on;
    }

    return 'CREATE INDEX IF NOT EXISTS ' . $name . ' ON ' . $on;
}

/**
 * SHOW TABLES, SHOW COLUMNS och information_schema hör hemma i tick och CLI.
 * En GET-sida läser inte schemat.
 */
function jeab_schema_probe_allowed(): bool
{
    if (!empty($GLOBALS['_JEAB_TICK']) || !empty($GLOBALS['_JEAB_SCHEMA_DDL']) || !empty($GLOBALS['_JEAB_DATA_MIGRATE']) || !empty($GLOBALS['_JEAB_MIGRATE_RUNS'])) {
        return true;
    }
    $sapi = (string) ($GLOBALS['_JEAB_SAPI'] ?? PHP_SAPI);
    if ($sapi === 'cli' || $sapi === 'phpdbg') {
        return true;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    return $method !== 'GET' && $method !== 'HEAD';
}

/** Bara ok är klart. fail: är ett misslyckat försök, inte ett stopp. */
function jeab_schema_flag_ok(?string $flag): bool
{
    return $flag === 'ok';
}

/**
 * Sidan hoppar över index vid ok och vid fail:.
 * Tick och migrate läser bara ok, så fail: försöks igen.
 */
function jeab_schema_flag_done(?string $flag): bool
{
    return jeab_schema_flag_ok($flag) || ($flag !== null && str_starts_with($flag, 'fail:'));
}

/**
 * Ett vanligt kontorsklick ska inte CREATE INDEX. Tick, migrate och CLI får det.
 * Migrate sätter _JEAB_MIGRATE_RUNS innan den kallar ensure, så första schemat
 * fortfarande skapas. _JEAB_SCHEMA_DDL är en engångskörning.
 */
function jeab_schema_request_skips_ddl(): bool
{
    if (!empty($GLOBALS['_JEAB_SCHEMA_DDL']) || !empty($GLOBALS['_JEAB_TICK'])) {
        return false;
    }
    if (!empty($GLOBALS['_JEAB_MIGRATE_RUNS'])) {
        return false;
    }
    if (function_exists('jeab_page_block_cli') && jeab_page_block_cli()) {
        return false;
    }
    $sapi = (string) ($GLOBALS['_JEAB_SAPI'] ?? PHP_SAPI);
    if ($sapi === 'cli' || $sapi === 'phpdbg') {
        return false;
    }
    if (!empty($GLOBALS['_JEAB_PAGE_REQUEST'])) {
        return true;
    }
    // Filter, vy och kort är GET med intent. De ska inte skapa schema.
    if (function_exists('jeab_request_is_read_fragment') && jeab_request_is_read_fragment()) {
        return true;
    }

    return false;
}

/** GET-fragment (filter, vy, kort, kalendervåg). Ingen DDL och inget tick-larm. */
function jeab_request_is_read_fragment(): bool
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return false;
    }
    if (function_exists('jeab_inkorg_ix_part') && jeab_inkorg_ix_part() !== '') {
        return true;
    }
    $intent = strtolower(trim((string) ($_GET['intent'] ?? '')));

    return in_array($intent, ['sync', 'more', 'page', 'search', 'list', 'get', 'card', 'side', 'threads', 'rows', 'calendar-log', 'reminder-names', 'calendar-bookings', 'calendar-outlook', 'calendar-match-debug'], true);
}

/**
 * Sant när flaggan redan är satt eller det här anropet är ett kontorsklick.
 * Anroparen skapar då inget och kör ingen SHOW INDEX.
 */
function jeab_schema_ddl_blocked(PDO $pdo, string $driver, string $flag): bool
{
    if (jeab_schema_flag_ok(jeab_settings_value($pdo, $driver, $flag))) {
        return true;
    }

    return jeab_schema_request_skips_ddl();
}

/** Test kan blockera ett namn via $_JEAB_LIST_INDEX_FAIL. */
function jeab_list_index_create(PDO $pdo, string $sql): void
{
    if (jeab_pdo_tx_busy($pdo)) {
        return;
    }
    $blocked = $GLOBALS['_JEAB_LIST_INDEX_FAIL'] ?? null;
    if (is_array($blocked)) {
        foreach ($blocked as $name) {
            if (is_string($name) && $name !== '' && preg_match('/\b' . preg_quote($name, '/') . '\b/', $sql) === 1) {
                throw new RuntimeException('CREATE INDEX blocked: ' . $name);
            }
        }
    }
    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        // Demo: index finns redan (MySQL 1061 / 1060). Ingen fara, gå vidare.
        if ((int) ($e->errorInfo[1] ?? 0) === 1061) {
            return;
        }
        throw $e;
    }
}

function jeab_settings_error_is_busy(Throwable $e): bool
{
    if ($e instanceof JeabDbBusyException) {
        return true;
    }
    if (jeab_pdo_is_max_user_connections($e)) {
        return true;
    }
    $msg = strtolower($e->getMessage());
    foreach (['database is locked', 'database is busy', 'lock wait timeout', 'deadlock', '[1205]', '[1213]'] as $needle) {
        if (str_contains($msg, $needle)) {
            return true;
        }
    }

    return false;
}

/** @return array<string, array{ok:bool,busy:bool,value:?string}> */
function &jeab_settings_request_cache(): array
{
    static $cache = [];

    return $cache;
}

function jeab_settings_cache_key(PDO $pdo, string $driver, string $key): string
{
    return spl_object_id($pdo) . "\0" . $driver . "\0" . $key;
}

function jeab_settings_cache_forget(PDO $pdo, string $driver, string $key): void
{
    $cache = &jeab_settings_request_cache();
    unset($cache[jeab_settings_cache_key($pdo, $driver, $key)]);
}

function jeab_settings_cache_store(PDO $pdo, string $driver, string $key, ?string $value): void
{
    $cache = &jeab_settings_request_cache();
    $cache[jeab_settings_cache_key($pdo, $driver, $key)] = [
        'ok' => true,
        'busy' => false,
        'value' => $value,
    ];
}

/**
 * @return array{ok:bool,busy:bool,value:?string}
 */
function jeab_settings_read(PDO $pdo, string $driver, string $key): array
{
    if (!empty($GLOBALS['_JEAB_SETTINGS_BUSY'])) {
        return ['ok' => false, 'busy' => true, 'value' => null];
    }
    $cache = &jeab_settings_request_cache();
    $ck = jeab_settings_cache_key($pdo, $driver, $key);
    if (array_key_exists($ck, $cache)) {
        return $cache[$ck];
    }
    try {
        $col = $driver === 'mysql' ? '`key`' : '"key"';
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE ' . $col . ' = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            $read = ['ok' => true, 'busy' => false, 'value' => null];
        } else {
            $read = ['ok' => true, 'busy' => false, 'value' => (string) $value];
        }
        $cache[$ck] = $read;

        return $read;
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'busy' => jeab_settings_error_is_busy($e),
            'value' => null,
        ];
    }
}

function jeab_settings_value(PDO $pdo, string $driver, string $key): ?string
{
    $read = jeab_settings_read($pdo, $driver, $key);

    return $read['ok'] ? $read['value'] : null;
}

/**
 * Samma fönster som COALESCE(NULLIF(ends_at, ''), starts_at). Tomt slut blir starten.
 */
function jeab_booking_ends_sort_value(?string $endsAt, ?string $startsAt): string
{
    $ends = (string) $endsAt;
    if ($ends === '') {
        return (string) $startsAt;
    }

    return $ends;
}

function jeab_sql_booking_ends_after(bool $endsSortColumn): string
{
    if ($endsSortColumn) {
        return "COALESCE(NULLIF(ends_sort, ''), COALESCE(NULLIF(ends_at, ''), starts_at)) > ?";
    }

    return "COALESCE(NULLIF(ends_at, ''), starts_at) > ?";
}

function jeab_booking_query_modes(): WeakMap
{
    static $modes = null;
    if (!$modes instanceof WeakMap) {
        $modes = new WeakMap();
    }

    return $modes;
}

/** @return array{ends_sort:bool,company:bool} */
function jeab_booking_query_mode(PDO $pdo): array
{
    $mode = jeab_booking_query_modes()[$pdo] ?? null;
    if (!is_array($mode)) {
        return ['ends_sort' => false, 'company' => false];
    }

    return [
        'ends_sort' => !empty($mode['ends_sort']),
        'company' => !empty($mode['company']),
    ];
}

function jeab_booking_query_mode_set(PDO $pdo, bool $endsSort, bool $company): void
{
    jeab_booking_query_modes()[$pdo] = ['ends_sort' => $endsSort, 'company' => $company];
}

/**
 * DDL och backfill hör hemma i migrate och tick, inte i en sidvisning.
 * Tick (även webb-tick) och data-migreringen får köra. CLI får köra i tester.
 * En vanlig webbförfrågan får inte det, även innan sidflaggan är satt.
 */
function jeab_booking_schema_work_allowed(): bool
{
    if (!empty($GLOBALS['_JEAB_TICK']) || !empty($GLOBALS['_JEAB_DATA_MIGRATE'])) {
        return true;
    }
    if (!empty($GLOBALS['_JEAB_PAGE_REQUEST'])) {
        return false;
    }
    $sapi = (string) ($GLOBALS['_JEAB_BOOKING_SAPI'] ?? PHP_SAPI);

    return $sapi === 'cli' || $sapi === 'phpdbg';
}

function jeab_booking_column_cache_forget(PDO $pdo, string $column): void
{
    $bag = &$GLOBALS['_JEAB_BOOKING_COL'];
    if (!is_array($bag)) {
        return;
    }
    unset($bag[spl_object_id($pdo) . ':' . $column]);
}

function jeab_booking_table_has_column(PDO $pdo, string $column, string $driver): bool
{
    $bag = &$GLOBALS['_JEAB_BOOKING_COL'];
    if (!is_array($bag)) {
        $bag = [];
    }
    $key = spl_object_id($pdo) . ':' . $column;
    if (array_key_exists($key, $bag)) {
        return true;
    }
    $has = false;
    if (function_exists('jeab_schema_probe_allowed') && !jeab_schema_probe_allowed()) {
        return false;
    }
    if ($driver === 'mysql') {
        $stmt = $pdo->query('SHOW COLUMNS FROM bookings LIKE ' . $pdo->quote($column));
        $has = $stmt instanceof PDOStatement && $stmt->fetch() !== false;
    } else {
        $stmt = $pdo->query('PRAGMA table_info(bookings)');
        foreach ($stmt ? $stmt->fetchAll() : [] as $col) {
            if (is_array($col) && (string) ($col['name'] ?? '') === $column) {
                $has = true;
                break;
            }
        }
    }
    if ($has) {
        $bag[$key] = true;
    }

    return $has;
}

/** Sant när kolumnen finns, oavsett minnesflaggan från en tidigare läsning. */
function jeab_booking_ends_sort_column(PDO $pdo): bool
{
    $mode = jeab_booking_query_modes()[$pdo] ?? null;
    if (is_array($mode) && !empty($mode['ends_sort'])) {
        return true;
    }
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    } catch (Throwable) {
        return false;
    }
    if (!jeab_booking_table_has_column($pdo, 'ends_sort', $driver)) {
        return false;
    }
    $company = is_array($mode) && !empty($mode['company']);
    jeab_booking_query_mode_set($pdo, true, $company);

    return true;
}

/**
 * Index (company, status, starts_at). Egen flagga så list_indexes_v2 inte körs om.
 * fail: blir inte permanent. Nästa tick får försöka igen.
 */
function jeab_ensure_booking_company_status_index(PDO $pdo, string $driver): void
{
    if (!jeab_booking_schema_work_allowed() || jeab_pdo_tx_busy($pdo)) {
        return;
    }
    $key = 'bookings_company_status_starts_v1';
    $flag = jeab_settings_value($pdo, $driver, $key);
    if ($flag === 'ok') {
        return;
    }
    try {
        $have = jeab_existing_index_names($pdo, 'bookings', $driver);
    } catch (Throwable) {
        return;
    }
    if (!in_array('bookings_company_status_starts', $have, true)) {
        foreach (['company', 'status', 'starts_at'] as $column) {
            if (!jeab_index_column_ok($pdo, 'bookings', $column, $driver)) {
                return;
            }
        }
        $sql = $driver === 'mysql'
            ? 'CREATE INDEX bookings_company_status_starts ON bookings (company, status, starts_at)'
            : 'CREATE INDEX IF NOT EXISTS bookings_company_status_starts ON bookings (company, status, starts_at)';
        try {
            jeab_list_index_create($pdo, $sql);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (stripos($message, 'Duplicate') === false && stripos($message, 'already exists') === false) {
                error_log('booking company index ' . $e::class);

                return;
            }
        }
    }
    jeab_settings_put($pdo, $driver, $key, 'ok');
}

/**
 * Läser bara kolumn och flagga. Ingen ALTER, ingen UPDATE, inget index.
 *
 * @return array{ends_sort:bool,company:bool}
 */
function jeab_booking_query_mode_read(PDO $pdo, string $driver): array
{
    $week = jeab_settings_value($pdo, $driver, 'bookings_week_query_v1');
    $ends = is_string($week) && str_starts_with($week, 'ok');
    if (!$ends) {
        try {
            $ends = jeab_booking_table_has_column($pdo, 'ends_sort', $driver);
        } catch (Throwable) {
            $ends = false;
        }
    }
    $canon = jeab_settings_value($pdo, $driver, 'bookings_company_canon_v1');
    $company = is_string($canon) && str_starts_with($canon, 'ok');
    jeab_booking_query_mode_set($pdo, $ends, $company);

    return jeab_booking_query_mode($pdo);
}

/**
 * Anslutningen läser läget. Själva arbetet görs bara när tick eller migrate får skriva.
 */
function jeab_booking_week_query_boot(PDO $pdo, string $driver): void
{
    jeab_booking_week_query_migrate($pdo, $driver);
    jeab_booking_company_canon($pdo, $driver);
    jeab_booking_query_mode_read($pdo, $driver);
}

/**
 * ends_sort och indexet. Rör inte company. Kontor i kontakter läser den kolumnen.
 */
function jeab_booking_week_query_migrate(PDO $pdo, string $driver): void
{
    if (!jeab_booking_schema_work_allowed() || jeab_pdo_tx_busy($pdo)) {
        return;
    }
    $key = 'bookings_week_query_v1';
    $flag = jeab_settings_value($pdo, $driver, $key);
    $done = is_string($flag) && str_starts_with($flag, 'ok');
    if (!$done) {
        $type = $driver === 'mysql' ? 'VARCHAR(32) DEFAULT NULL' : 'TEXT';
        try {
            jeab_ensure_table_columns($pdo, 'bookings', ['ends_sort' => $type], $driver);
            $pdo->exec(
                "UPDATE bookings SET ends_sort = CASE WHEN ends_at IS NULL OR ends_at = '' THEN starts_at ELSE ends_at END"
                . " WHERE ends_sort IS NULL OR ends_sort = ''"
            );
        } catch (Throwable $e) {
            error_log('booking week query migrate ' . $e::class);

            return;
        }
        if (!jeab_settings_put($pdo, $driver, $key, 'ok')) {
            return;
        }
        jeab_booking_column_cache_forget($pdo, 'ends_sort');
    }
    jeab_ensure_booking_company_status_index($pdo, $driver);
}

/** Gamla bolagsvärdet före kanoniseringen. Tomt blir tom sträng. */
function jeab_ensure_booking_company_audit(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS booking_company_audit (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                booking_id INT NOT NULL,
                old_company VARCHAR(191) NOT NULL DEFAULT \'\',
                new_company VARCHAR(32) NOT NULL,
                PRIMARY KEY (id),
                KEY booking_company_audit_booking (booking_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS booking_company_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            booking_id INTEGER NOT NULL,
            old_company TEXT NOT NULL DEFAULT \'\',
            new_company TEXT NOT NULL
        )'
    );
}

/**
 * Eget steg från ends_sort. Skriver om company till jarfalla eller malmo och
 * sparar det gamla värdet i booking_company_audit. Kontakter som saknar bolag
 * försvinner från Malmö när en tom bokning blir jarfalla. Det sker bara här.
 */
function jeab_booking_company_canon(PDO $pdo, string $driver): void
{
    if (!jeab_booking_schema_work_allowed() || jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if (!function_exists('jeab_normalize_company')) {
        return;
    }
    $key = 'bookings_company_canon_v1';
    $flag = jeab_settings_value($pdo, $driver, $key);
    if (is_string($flag) && str_starts_with($flag, 'ok')) {
        return;
    }
    try {
        $stmt = $pdo->query("SELECT id, company FROM bookings WHERE company IS NULL OR company NOT IN ('jarfalla', 'malmo')");
        $rows = $stmt ? $stmt->fetchAll() : [];
    } catch (Throwable $e) {
        error_log('booking company canon ' . $e::class);

        return;
    }
    $blank = 0;
    $canon = 0;
    if ($rows !== []) {
        try {
            // DDL före transaktionen. MySQL committar implicit på CREATE TABLE.
            jeab_ensure_booking_company_audit($pdo, $driver);
            $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
            jeab_pdo_transaction($pdo, static function (PDO $tx) use ($rows, $now, &$blank, &$canon): void {
                $upd = $tx->prepare('UPDATE bookings SET company = ? WHERE id = ?');
                $log = $tx->prepare(
                    'INSERT INTO booking_company_audit (created_at, booking_id, old_company, new_company) VALUES (?, ?, ?, ?)'
                );
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $raw = $row['company'] ?? null;
                    $old = $raw === null ? '' : (string) $raw;
                    if (trim($old) === '') {
                        $blank++;
                    }
                    $next = jeab_normalize_company($raw) ?? 'jarfalla';
                    if ($next === $old) {
                        continue;
                    }
                    // UPDATE före loggen. En krasch däremellan rullar tillbaka båda.
                    $upd->execute([$next, (int) $row['id']]);
                    $log->execute([$now, (int) $row['id'], $old, $next]);
                    $canon++;
                }
            });
        } catch (Throwable $e) {
            error_log('booking company canon ' . $e::class);

            return;
        }
    }
    if ($canon > 0) {
        error_log('booking company canon blank=' . $blank . ' canon=' . $canon);
    }
    jeab_settings_put($pdo, $driver, $key, 'ok:blank=' . $blank . ',canon=' . $canon);
}

/**
 * Listan filtrerar list_id, öppen/stängd, bolag och förfallodag.
 * Egen flagga så en redan satt list_indexes_v2 ändå skapar indexet.
 */
function jeab_ensure_reminder_list_index(PDO $pdo, string $driver): void
{
    if (jeab_pdo_tx_busy($pdo)) {
        return;
    }
    jeab_ensure_reminder_list_open_index($pdo, $driver);
    jeab_ensure_reminder_done_page_index($pdo, $driver);
    jeab_ensure_reminder_tally_index($pdo, $driver);
}

/**
 * (list_id, completed_at, company, due_at). Flaggan reminders_list_open_v1.
 */
function jeab_ensure_reminder_list_open_index(PDO $pdo, string $driver): void
{
    $key = 'reminders_list_open_v1';
    if (jeab_settings_value($pdo, $driver, $key) === 'ok') {
        return;
    }
    try {
        $have = jeab_existing_index_names($pdo, 'reminders', $driver);
    } catch (Throwable) {
        return;
    }
    if (!in_array('reminders_list_open', $have, true)) {
        foreach (['list_id', 'completed_at', 'company', 'due_at'] as $column) {
            if (!jeab_index_column_ok($pdo, 'reminders', $column, $driver)) {
                return;
            }
        }
        $sql = $driver === 'mysql'
            ? 'CREATE INDEX reminders_list_open ON reminders (list_id, completed_at, company, due_at)'
            : 'CREATE INDEX IF NOT EXISTS reminders_list_open ON reminders (list_id, completed_at, company, due_at)';
        try {
            jeab_list_index_create($pdo, $sql);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (stripos($message, 'Duplicate') === false && stripos($message, 'already exists') === false) {
                return;
            }
        }
    }
    jeab_settings_put($pdo, $driver, $key, 'ok');
}

/**
 * Klara sorteras på completed_at. Indexet gör LIMIT 50 utan att sortera hela tabellen.
 * Tick skapar det. En sidvisning gör det inte.
 */
function jeab_ensure_reminder_done_page_index(PDO $pdo, string $driver): void
{
    if (jeab_pdo_tx_busy($pdo) || jeab_schema_request_skips_ddl()) {
        return;
    }
    $key = 'reminders_done_page_v1';
    if (jeab_settings_value($pdo, $driver, $key) === 'ok') {
        return;
    }
    try {
        $have = jeab_existing_index_names($pdo, 'reminders', $driver);
    } catch (Throwable) {
        return;
    }
    if (!in_array('reminders_done_page', $have, true)) {
        foreach (['list_id', 'completed_at', 'id'] as $column) {
            if (!jeab_index_column_ok($pdo, 'reminders', $column, $driver)) {
                return;
            }
        }
        $sql = $driver === 'mysql'
            ? 'CREATE INDEX reminders_done_page ON reminders (list_id, completed_at, id)'
            : 'CREATE INDEX IF NOT EXISTS reminders_done_page ON reminders (list_id, completed_at, id)';
        try {
            jeab_list_index_create($pdo, $sql);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (stripos($message, 'Duplicate') === false && stripos($message, 'already exists') === false) {
                return;
            }
        }
    }
    jeab_settings_put($pdo, $driver, $key, 'ok');
}

/**
 * Räkningen filtrerar list_id och bolag. Tick skapar indexet. En sidvisning gör det inte.
 */
function jeab_ensure_reminder_tally_index(PDO $pdo, string $driver): void
{
    if (jeab_pdo_tx_busy($pdo) || jeab_schema_request_skips_ddl()) {
        return;
    }
    $key = 'reminders_tally_v1';
    if (jeab_settings_value($pdo, $driver, $key) === 'ok') {
        return;
    }
    try {
        $have = jeab_existing_index_names($pdo, 'reminders', $driver);
    } catch (Throwable) {
        return;
    }
    if (!in_array('reminders_tally', $have, true)) {
        foreach (['list_id', 'company', 'completed_at', 'due_at'] as $column) {
            if (!jeab_index_column_ok($pdo, 'reminders', $column, $driver)) {
                return;
            }
        }
        $sql = $driver === 'mysql'
            ? 'CREATE INDEX reminders_tally ON reminders (list_id, company, completed_at, due_at)'
            : 'CREATE INDEX IF NOT EXISTS reminders_tally ON reminders (list_id, company, completed_at, due_at)';
        try {
            jeab_list_index_create($pdo, $sql);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (stripos($message, 'Duplicate') === false && stripos($message, 'already exists') === false) {
                return;
            }
        }
    }
    jeab_settings_put($pdo, $driver, $key, 'ok');
}

function jeab_settings_put(PDO $pdo, string $driver, string $key, string $value): bool
{
    try {
        if ($driver === 'mysql') {
            $sql = 'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)';
        } else {
            $sql = 'INSERT INTO settings ("key", value) VALUES (?, ?) ON CONFLICT("key") DO UPDATE SET value = excluded.value';
        }
        $pdo->prepare($sql)->execute([$key, $value]);
        jeab_settings_cache_store($pdo, $driver, $key, $value);

        return true;
    } catch (Throwable) {
        jeab_settings_cache_forget($pdo, $driver, $key);

        return false;
    }
}

function jeab_db_lock_name(string $name): string
{
    $name = strtolower(trim($name));
    if (preg_match('/\A[a-z0-9_]{1,64}\z/', $name) !== 1) {
        return 'jeab_lock';
    }

    return $name;
}

/** @return array<string, mixed> */
function &jeab_db_lock_bag(): array
{
    if (!isset($GLOBALS['_JEAB_DB_LOCKS']) || !is_array($GLOBALS['_JEAB_DB_LOCKS'])) {
        $GLOBALS['_JEAB_DB_LOCKS'] = [];
    }

    return $GLOBALS['_JEAB_DB_LOCKS'];
}

function jeab_db_lock_file(PDO $pdo, string $name): string
{
    $file = '';
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            $row = $pdo->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $file = (string) ($row['file'] ?? '');
            }
        }
    } catch (Throwable) {
        $file = '';
    }
    if ($file === '') {
        $file = sys_get_temp_dir() . '/jeab-lock-' . spl_object_id($pdo);
    }

    return $file . '.' . $name . '.lock';
}

/** Icke-blockerande. MySQL: GET_LOCK(namn, 0). SQLite: flock så tester kan köra två processer. */
function jeab_db_get_lock(PDO $pdo, string $name): bool
{
    $name = jeab_db_lock_name($name);
    $key = spl_object_id($pdo) . ':' . $name;
    $bag = &jeab_db_lock_bag();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        try {
            $stmt = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($name) . ', 0)');
            $got = $stmt ? (int) $stmt->fetchColumn() : 0;
        } catch (Throwable) {
            return false;
        }
        if ($got !== 1) {
            return false;
        }
        $bag[$key] = true;

        return true;
    }
    $fh = @fopen(jeab_db_lock_file($pdo, $name), 'c');
    if ($fh === false) {
        return false;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);

        return false;
    }
    $bag[$key] = $fh;

    return true;
}

function jeab_db_release_lock(PDO $pdo, string $name): void
{
    $name = jeab_db_lock_name($name);
    $key = spl_object_id($pdo) . ':' . $name;
    $bag = &jeab_db_lock_bag();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        if (!empty($bag[$key])) {
            try {
                $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($name) . ')');
            } catch (Throwable) {
            }
        }
        unset($bag[$key]);

        return;
    }
    $fh = $bag[$key] ?? null;
    if (is_resource($fh)) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    unset($bag[$key]);
}

function jeab_existing_index_names(PDO $pdo, string $table, string $driver): array
{
    if (function_exists('jeab_schema_probe_allowed') && !jeab_schema_probe_allowed()) {
        return [];
    }
    $names = [];
    if ($driver === 'mysql') {
        $stmt = $pdo->query('SHOW INDEX FROM ' . $table);
        foreach ($stmt ? $stmt->fetchAll() : [] as $row) {
            $names[] = (string) ($row['Key_name'] ?? '');
        }
    } else {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = " . $pdo->quote($table));
        foreach ($stmt ? $stmt->fetchAll() : [] as $row) {
            $names[] = (string) ($row['name'] ?? '');
        }
    }
    return $names;
}

function jeab_index_column_ok(PDO $pdo, string $table, string $column, string $driver): bool
{
    if (function_exists('jeab_schema_probe_allowed') && !jeab_schema_probe_allowed()) {
        return true;
    }
    if ($driver !== 'mysql') {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        foreach ($stmt ? $stmt->fetchAll() : [] as $col) {
            if ((string) ($col['name'] ?? '') === $column) {
                return true;
            }
        }
        return false;
    }
    $stmt = $pdo->query('SHOW COLUMNS FROM ' . $table . ' LIKE ' . $pdo->quote($column));
    $col = $stmt ? $stmt->fetch() : false;
    if (!$col) {
        return false;
    }
    $type = strtolower((string) ($col['Type'] ?? ''));
    if (preg_match('/varchar\((\d+)\)/', $type, $m) === 1) {
        return (int) $m[1] <= 191;
    }
    if (preg_match('/char\((\d+)\)/', $type, $m) === 1) {
        return (int) $m[1] <= 191;
    }
    return preg_match('/^(datetime|timestamp|date|int|bigint|tinyint|smallint|mediumint)/', $type) === 1;
}

function jeab_ensure_reminder_ort_column(PDO $pdo, string $driver): void
{
    $type = $driver === 'mysql' ? 'VARCHAR(191) DEFAULT NULL' : 'TEXT';
    jeab_ensure_table_columns($pdo, 'reminders', ['ort' => $type], $driver);
}

function jeab_ensure_reminder_postal_column(PDO $pdo, string $driver): void
{
    $type = $driver === 'mysql' ? 'VARCHAR(16) DEFAULT NULL' : 'TEXT';
    jeab_ensure_table_columns($pdo, 'reminders', ['postal' => $type], $driver);
}

function jeab_ensure_reminder_tap_columns(PDO $pdo, string $driver): void
{
    $type = $driver === 'mysql' ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
    jeab_ensure_table_columns($pdo, 'reminders', [
        'ringt' => $type,
        'pratat' => $type,
        'mailat' => $type,
        'smsat' => $type,
    ], $driver);
}

function jeab_ensure_reminder_apple_columns(PDO $pdo, string $driver): void
{
    jeab_ensure_table_columns($pdo, 'reminders', [
        'flagged' => $driver === 'mysql' ? 'TINYINT(1) NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0',
        'tags' => 'TEXT',
    ], $driver);
}

function jeab_ensure_table_columns(PDO $pdo, string $table, array $wanted, string $driver, bool $force = false): void
{
    foreach (jeab_copy_columns($table) as $copyCol) {
        if (array_key_exists($copyCol, $wanted) && jeab_copy_columns_dropped($pdo, $table)) {
            unset($wanted[$copyCol]);
        }
    }
    if ($wanted === []) {
        return;
    }
    if (!$force && function_exists('jeab_schema_probe_allowed') && !jeab_schema_probe_allowed()) {
        return;
    }
    if (jeab_pdo_tx_busy($pdo)) {
        return;
    }
    $existing = [];
    if ($driver === 'mysql') {
        $stmt = $pdo->query('SHOW COLUMNS FROM ' . $table);
        foreach ($stmt->fetchAll() as $col) {
            $existing[] = (string) $col['Field'];
        }
    } else {
        foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $col) {
            $existing[] = (string) $col['name'];
        }
    }
    foreach ($wanted as $name => $type) {
        if (!in_array($name, $existing, true)) {
            $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $name . ' ' . $type);
        }
    }
}

/** Vem som raderade en order via ordrar.delete. Idempotent. Ingen JSON-fil. */
function jeab_ensure_order_delete_audit(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS order_delete_audit (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                reminder_id INT NOT NULL DEFAULT 0,
                actor VARCHAR(191) NOT NULL DEFAULT \'\',
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS order_delete_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            reminder_id INTEGER NOT NULL DEFAULT 0,
            actor TEXT NOT NULL DEFAULT \'\'
        )'
    );
}

/** Historik när en order får ägare från påminnelsens ansvarige. Idempotent. Ingen JSON-fil. */
function jeab_ensure_order_owner_audit(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS order_owner_audit (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                reminder_id INT NOT NULL DEFAULT 0,
                owner VARCHAR(191) NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS order_owner_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            reminder_id INTEGER NOT NULL DEFAULT 0,
            owner TEXT NOT NULL
        )'
    );
}

/**
 * Ångra ägare, adress eller e-post på en påminnelse. Bara skalära kolumner.
 * Ingen JSON-kolumn och ingen JSON-fil.
 * Flaggan reminder_admin_audit_v1 och minnet hoppar över DDL på senare anrop.
 */
function jeab_ensure_reminder_admin_audit(PDO $pdo, string $driver): void
{
    static $done = [];
    $id = spl_object_id($pdo);
    if (isset($done[$id])) {
        return;
    }
    $driver = $driver === 'mysql' ? 'mysql' : 'sqlite';
    if (function_exists('jeab_settings_value') && jeab_settings_value($pdo, $driver, 'reminder_admin_audit_v1') === '1') {
        $done[$id] = true;

        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    $GLOBALS['_JEAB_REMINDER_ADMIN_AUDIT_DDL'] = (int) ($GLOBALS['_JEAB_REMINDER_ADMIN_AUDIT_DDL'] ?? 0) + 1;
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS reminder_admin_audit (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                reminder_id INT NOT NULL,
                action VARCHAR(32) NOT NULL,
                old_owner VARCHAR(191) NOT NULL DEFAULT \'\',
                new_owner VARCHAR(191) NOT NULL DEFAULT \'\',
                old_street VARCHAR(255) NOT NULL DEFAULT \'\',
                new_street VARCHAR(255) NOT NULL DEFAULT \'\',
                old_zip VARCHAR(16) NOT NULL DEFAULT \'\',
                new_zip VARCHAR(16) NOT NULL DEFAULT \'\',
                old_city VARCHAR(191) NOT NULL DEFAULT \'\',
                new_city VARCHAR(191) NOT NULL DEFAULT \'\',
                contact_id VARCHAR(32) NOT NULL DEFAULT \'\',
                old_contact_street VARCHAR(255) NOT NULL DEFAULT \'\',
                old_contact_zip VARCHAR(16) NOT NULL DEFAULT \'\',
                old_contact_city VARCHAR(191) NOT NULL DEFAULT \'\',
                new_contact_street VARCHAR(255) NOT NULL DEFAULT \'\',
                new_contact_zip VARCHAR(16) NOT NULL DEFAULT \'\',
                new_contact_city VARCHAR(191) NOT NULL DEFAULT \'\',
                old_email VARCHAR(191) NOT NULL DEFAULT \'\',
                new_email VARCHAR(191) NOT NULL DEFAULT \'\',
                undone TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY reminder_admin_audit_reminder (reminder_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS reminder_admin_audit (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL,
                reminder_id INTEGER NOT NULL,
                action TEXT NOT NULL,
                old_owner TEXT NOT NULL DEFAULT \'\',
                new_owner TEXT NOT NULL DEFAULT \'\',
                old_street TEXT NOT NULL DEFAULT \'\',
                new_street TEXT NOT NULL DEFAULT \'\',
                old_zip TEXT NOT NULL DEFAULT \'\',
                new_zip TEXT NOT NULL DEFAULT \'\',
                old_city TEXT NOT NULL DEFAULT \'\',
                new_city TEXT NOT NULL DEFAULT \'\',
                contact_id TEXT NOT NULL DEFAULT \'\',
                old_contact_street TEXT NOT NULL DEFAULT \'\',
                old_contact_zip TEXT NOT NULL DEFAULT \'\',
                old_contact_city TEXT NOT NULL DEFAULT \'\',
                new_contact_street TEXT NOT NULL DEFAULT \'\',
                new_contact_zip TEXT NOT NULL DEFAULT \'\',
                new_contact_city TEXT NOT NULL DEFAULT \'\',
                old_email TEXT NOT NULL DEFAULT \'\',
                new_email TEXT NOT NULL DEFAULT \'\',
                undone INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS reminder_admin_audit_reminder ON reminder_admin_audit (reminder_id)');
    }
    $emailCol = $driver === 'mysql' ? "VARCHAR(191) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    jeab_ensure_table_columns($pdo, 'reminder_admin_audit', [
        'old_email' => $emailCol,
        'new_email' => $emailCol,
    ], $driver);
    if (function_exists('jeab_settings_put')) {
        jeab_settings_put($pdo, $driver, 'reminder_admin_audit_v1', '1');
    }
    $done[$id] = true;
}

/** Att mobilfältet på en order ändrades. Bara id, bolag och orsak. Ingen JSON-fil och inget nummer. */
function jeab_ensure_order_mobil_audit(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS order_mobil_audit (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                reminder_id INT NOT NULL DEFAULT 0,
                company VARCHAR(32) NOT NULL,
                reason VARCHAR(200) NOT NULL,
                change_kind VARCHAR(16) NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS order_mobil_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            reminder_id INTEGER NOT NULL DEFAULT 0,
            company TEXT NOT NULL,
            reason TEXT NOT NULL,
            change_kind TEXT NOT NULL
        )'
    );
}

/** På/av per notis till slutkund. Idempotent. Ingen JSON-fil. */
function jeab_ensure_notis_switches(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS notis_switches (
                notis_id VARCHAR(64) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME NOT NULL,
                updated_by VARCHAR(191) NOT NULL DEFAULT \'\',
                PRIMARY KEY (notis_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS notis_switches (
            notis_id TEXT PRIMARY KEY,
            enabled INTEGER NOT NULL DEFAULT 1,
            updated_at TEXT NOT NULL,
            updated_by TEXT NOT NULL DEFAULT \'\'
        )'
    );
}

/**
 * Tidpunkt per notisrad. Ingen kundadress.
 * ok=0 är ett misslyckat försök. is_test=1 är en testkopia, inte ett kundutskick.
 */
function jeab_ensure_notis_sends(PDO $pdo, string $driver): void
{
    if (empty($GLOBALS['_JEAB_NOTIS_SEND_DDL']) && function_exists('jeab_schema_request_skips_ddl') && jeab_schema_request_skips_ddl()) {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS notis_sends (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                notis_id VARCHAR(64) NOT NULL,
                sent_at DATETIME NOT NULL,
                ok TINYINT(1) NOT NULL DEFAULT 1,
                is_test TINYINT(1) NOT NULL DEFAULT 0,
                why VARCHAR(191) NOT NULL DEFAULT \'\',
                PRIMARY KEY (id),
                KEY notis_sends_id_at (notis_id, sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS notis_sends (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            notis_id TEXT NOT NULL,
            sent_at TEXT NOT NULL,
            ok INTEGER NOT NULL DEFAULT 1,
            is_test INTEGER NOT NULL DEFAULT 0,
            why TEXT NOT NULL DEFAULT \'\'
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS notis_sends_id_at ON notis_sends (notis_id, sent_at)');
}

/**
 * Senaste härledda utskick, högst 200 rader. Tick skriver. Sidan läser.
 * Ingen kundadress.
 */
function jeab_ensure_notis_history(PDO $pdo, string $driver): void
{
    if (empty($GLOBALS['_JEAB_NOTIS_SEND_DDL']) && function_exists('jeab_schema_request_skips_ddl') && jeab_schema_request_skips_ddl()) {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS notis_history (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                notis_id VARCHAR(64) NOT NULL,
                sent_at DATETIME NOT NULL,
                ok TINYINT(1) NOT NULL DEFAULT 1,
                is_test TINYINT(1) NOT NULL DEFAULT 0,
                why VARCHAR(191) NOT NULL DEFAULT \'\',
                PRIMARY KEY (id),
                KEY notis_history_at (sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS notis_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            notis_id TEXT NOT NULL,
            sent_at TEXT NOT NULL,
            ok INTEGER NOT NULL DEFAULT 1,
            is_test INTEGER NOT NULL DEFAULT 0,
            why TEXT NOT NULL DEFAULT \'\'
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS notis_history_at ON notis_history (sent_at)');
}

/** Varför en lokal Fortnox-order släpptes. Bara nummer och orsak, inga kunduppgifter. */
function jeab_ensure_fortnox_order_audit(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS fortnox_order_audit (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                reminder_id INT NOT NULL DEFAULT 0,
                fortnox_order VARCHAR(32) NOT NULL DEFAULT \'\',
                reason VARCHAR(40) NOT NULL DEFAULT \'\',
                message VARCHAR(191) NOT NULL DEFAULT \'\',
                PRIMARY KEY (id),
                KEY fortnox_order_audit_reminder (reminder_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS fortnox_order_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            reminder_id INTEGER NOT NULL DEFAULT 0,
            fortnox_order TEXT NOT NULL DEFAULT \'\',
            reason TEXT NOT NULL DEFAULT \'\',
            message TEXT NOT NULL DEFAULT \'\'
        )'
    );
}

/** Markör för inbox.malmo_hs_sync. En rad. Ingen ny JSON-fil när SQL finns. */
/** Graph-webhookens kö. En rad per bolag och meddelande-id. Ingen JSON-fil. */
function jeab_ensure_graph_hook_queue(PDO $pdo, string $driver): void
{
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS graph_hook_queue (
                idem_key CHAR(64) NOT NULL,
                company VARCHAR(32) NOT NULL,
                message_id TEXT NOT NULL,
                queued_at DATETIME NOT NULL,
                attempts INT NOT NULL DEFAULT 0,
                PRIMARY KEY (idem_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS graph_hook_queue (
                idem_key TEXT PRIMARY KEY,
                company TEXT NOT NULL,
                message_id TEXT NOT NULL,
                queued_at TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0
            )'
        );
    }
    $attempts = $driver === 'mysql' ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
    jeab_ensure_table_columns($pdo, 'graph_hook_queue', ['attempts' => $attempts], $driver);
}

/**
 * Offertköns utbox och händelselogg.
 * Samma tabeller på en databas som redan har reminders, där schemafilen inte körs om.
 */
function jeab_ensure_quote_outbox(PDO $pdo, string $driver): void
{
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS quote_outbox (
                idem_key VARCHAR(255) NOT NULL,
                thread_id VARCHAR(191) NOT NULL,
                product VARCHAR(32) NOT NULL DEFAULT \'\',
                status VARCHAR(16) NOT NULL DEFAULT \'pending\',
                attempts INT NOT NULL DEFAULT 0,
                next_attempt_at INT UNSIGNED NOT NULL DEFAULT 0,
                reason VARCHAR(191) NOT NULL DEFAULT \'\',
                queued_at INT UNSIGNED NOT NULL,
                updated_at DATETIME NOT NULL,
                manual_send TINYINT(1) NOT NULL DEFAULT 0,
                guard_job INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (idem_key),
                KEY quote_outbox_thread (thread_id),
                KEY quote_outbox_due (status, next_attempt_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS quote_outbox_event (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                thread_id VARCHAR(191) NOT NULL,
                product VARCHAR(32) NOT NULL DEFAULT \'\',
                status VARCHAR(16) NOT NULL,
                reason VARCHAR(191) NOT NULL DEFAULT \'\',
                attempts INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY quote_outbox_event_thread (thread_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS quote_outbox_manual_undo (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                undo_id VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL,
                idem_key VARCHAR(255) NOT NULL,
                thread_id VARCHAR(191) NOT NULL,
                manual_send TINYINT(1) NOT NULL DEFAULT 0,
                undone_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY quote_outbox_manual_undo_undo_id (undo_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS quote_outbox (
                idem_key TEXT PRIMARY KEY,
                thread_id TEXT NOT NULL,
                product TEXT NOT NULL DEFAULT \'\',
                status TEXT NOT NULL DEFAULT \'pending\',
                attempts INTEGER NOT NULL DEFAULT 0,
                next_attempt_at INTEGER NOT NULL DEFAULT 0,
                reason TEXT NOT NULL DEFAULT \'\',
                queued_at INTEGER NOT NULL,
                updated_at TEXT NOT NULL,
                manual_send INTEGER NOT NULL DEFAULT 0,
                guard_job INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS quote_outbox_thread ON quote_outbox (thread_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS quote_outbox_due ON quote_outbox (status, next_attempt_at)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS quote_outbox_event (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL,
                thread_id TEXT NOT NULL,
                product TEXT NOT NULL DEFAULT \'\',
                status TEXT NOT NULL,
                reason TEXT NOT NULL DEFAULT \'\',
                attempts INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS quote_outbox_event_thread ON quote_outbox_event (thread_id)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS quote_outbox_manual_undo (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                undo_id TEXT NOT NULL,
                created_at TEXT NOT NULL,
                idem_key TEXT NOT NULL,
                thread_id TEXT NOT NULL,
                manual_send INTEGER NOT NULL DEFAULT 0,
                undone_at TEXT
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS quote_outbox_manual_undo_undo_id ON quote_outbox_manual_undo (undo_id)');
    }
    // Äldre tabeller saknar kolumnen. CREATE IF NOT EXISTS ändrar dem inte.
    // Kolumnen läggs till även när vanlig DDL är av, annars läses manual_send som 0.
    jeab_ensure_table_columns($pdo, 'quote_outbox', [
        'manual_send' => $driver === 'mysql' ? 'TINYINT(1) NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0',
        'guard_job' => $driver === 'mysql' ? 'INT UNSIGNED NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0',
    ], $driver, true);
}

function jeab_ensure_malmo_hs_cursor(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS malmo_hs_cursor (
                id TINYINT UNSIGNED NOT NULL,
                watermark VARCHAR(40) NOT NULL DEFAULT \'\',
                page INT UNSIGNED NOT NULL DEFAULT 1,
                cursor_offset INT UNSIGNED NOT NULL DEFAULT 0,
                query VARCHAR(80) NOT NULL DEFAULT \'\',
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS malmo_hs_cursor (
            id INTEGER PRIMARY KEY,
            watermark TEXT NOT NULL DEFAULT \'\',
            page INTEGER NOT NULL DEFAULT 1,
            cursor_offset INTEGER NOT NULL DEFAULT 0,
            query TEXT NOT NULL DEFAULT \'\'
        )'
    );
}

/** Manuell produkt från inbox.set_offer. Idempotent. Ingen JSON-fil. */
function jeab_ensure_inbox_thread_override(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inbox_thread_override (
                thread_id VARCHAR(64) NOT NULL,
                offer VARCHAR(32) NOT NULL DEFAULT \'\',
                primary_product VARCHAR(64) NOT NULL DEFAULT \'\',
                assignee VARCHAR(64) NOT NULL DEFAULT \'\',
                type VARCHAR(64) NOT NULL DEFAULT \'\',
                reason VARCHAR(191) NOT NULL DEFAULT \'\',
                manual TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME NOT NULL,
                `by` VARCHAR(64) NOT NULL DEFAULT \'\',
                PRIMARY KEY (thread_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inbox_thread_override (
            thread_id TEXT PRIMARY KEY,
            offer TEXT NOT NULL DEFAULT \'\',
            primary_product TEXT NOT NULL DEFAULT \'\',
            assignee TEXT NOT NULL DEFAULT \'\',
            type TEXT NOT NULL DEFAULT \'\',
            reason TEXT NOT NULL DEFAULT \'\',
            manual INTEGER NOT NULL DEFAULT 1,
            updated_at TEXT NOT NULL,
            "by" TEXT NOT NULL DEFAULT \'\'
        )'
    );
}

/** Audit när inbox.reclassify_leads byter Information till Ny lead. Idempotent. Ingen JSON-fil. */
/** Felklassning när en människa skickar offert på Information. Ingen kundadress. */
/** Hopp när service, support, reklamation, fel eller oklassat inte får påminnelse. Ingen JSON. */
function jeab_ensure_inbox_service_skip(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inbox_service_skip (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                thread_id VARCHAR(64) NOT NULL DEFAULT \'\',
                label VARCHAR(64) NOT NULL DEFAULT \'\',
                reason VARCHAR(200) NOT NULL DEFAULT \'\',
                path VARCHAR(32) NOT NULL DEFAULT \'\',
                PRIMARY KEY (id),
                KEY inbox_service_skip_thread (thread_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inbox_service_skip (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            thread_id TEXT NOT NULL DEFAULT \'\',
            label TEXT NOT NULL DEFAULT \'\',
            reason TEXT NOT NULL DEFAULT \'\',
            path TEXT NOT NULL DEFAULT \'\'
        )'
    );
}

function jeab_ensure_inbox_misclass(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inbox_misclass (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                thread_id VARCHAR(64) NOT NULL DEFAULT \'\',
                label VARCHAR(64) NOT NULL DEFAULT \'\',
                path VARCHAR(32) NOT NULL DEFAULT \'\',
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inbox_misclass (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            thread_id TEXT NOT NULL DEFAULT \'\',
            label TEXT NOT NULL DEFAULT \'\',
            path TEXT NOT NULL DEFAULT \'\'
        )'
    );
}

/** Utskick: via (auto|manual) och actor. Ingen mottagaradress. */
function jeab_ensure_inbox_sent(PDO $pdo, string $driver): void
{
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inbox_sent (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                thread_id VARCHAR(191) NOT NULL DEFAULT \'\',
                via VARCHAR(16) NOT NULL DEFAULT \'\',
                actor VARCHAR(191) NOT NULL DEFAULT \'\',
                kind VARCHAR(32) NOT NULL DEFAULT \'\',
                outcome VARCHAR(32) NOT NULL DEFAULT \'\',
                channel VARCHAR(16) NOT NULL DEFAULT \'\',
                body_hash CHAR(64) NOT NULL DEFAULT \'\',
                rcpt_hash CHAR(64) NOT NULL DEFAULT \'\',
                from_addr VARCHAR(191) NOT NULL DEFAULT \'\',
                snapshot_json LONGTEXT NULL,
                PRIMARY KEY (id),
                KEY inbox_sent_thread (thread_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        jeab_inbox_sent_add_columns($pdo, 'mysql');
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inbox_sent (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            thread_id TEXT NOT NULL DEFAULT \'\',
            via TEXT NOT NULL DEFAULT \'\',
            actor TEXT NOT NULL DEFAULT \'\',
            kind TEXT NOT NULL DEFAULT \'\',
            outcome TEXT NOT NULL DEFAULT \'\',
            channel TEXT NOT NULL DEFAULT \'\',
            body_hash TEXT NOT NULL DEFAULT \'\',
            rcpt_hash TEXT NOT NULL DEFAULT \'\',
            from_addr TEXT NOT NULL DEFAULT \'\',
            snapshot_json TEXT NOT NULL DEFAULT \'\'
        )'
    );
    jeab_inbox_sent_add_columns($pdo, 'sqlite');
}

/** Lägger till spärrens kolumner på en inbox_sent som skapades tidigare. */
function jeab_inbox_sent_add_columns(PDO $pdo, string $driver): void
{
    if (function_exists('jeab_schema_probe_allowed') && !jeab_schema_probe_allowed()) {
        return;
    }
    $wanted = $driver === 'mysql'
        ? [
            'outcome' => 'VARCHAR(32) NOT NULL DEFAULT \'\'',
            'channel' => 'VARCHAR(16) NOT NULL DEFAULT \'\'',
            'body_hash' => 'CHAR(64) NOT NULL DEFAULT \'\'',
            'rcpt_hash' => 'CHAR(64) NOT NULL DEFAULT \'\'',
            'from_addr' => 'VARCHAR(191) NOT NULL DEFAULT \'\'',
            'snapshot_json' => 'LONGTEXT NULL',
        ]
        : [
            'outcome' => 'TEXT NOT NULL DEFAULT \'\'',
            'channel' => 'TEXT NOT NULL DEFAULT \'\'',
            'body_hash' => 'TEXT NOT NULL DEFAULT \'\'',
            'rcpt_hash' => 'TEXT NOT NULL DEFAULT \'\'',
            'from_addr' => 'TEXT NOT NULL DEFAULT \'\'',
            'snapshot_json' => 'TEXT NOT NULL DEFAULT \'\'',
        ];
    $have = [];
    if ($driver === 'mysql') {
        $stmt = $pdo->query('SHOW COLUMNS FROM inbox_sent');
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $col) {
            if (is_array($col)) {
                $have[strtolower((string) ($col['Field'] ?? ''))] = true;
            }
        }
    } else {
        $stmt = $pdo->query('PRAGMA table_info(inbox_sent)');
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $col) {
            if (is_array($col)) {
                $have[strtolower((string) ($col['name'] ?? ''))] = true;
            }
        }
    }
    foreach ($wanted as $name => $ddl) {
        if (empty($have[$name])) {
            $pdo->exec('ALTER TABLE inbox_sent ADD COLUMN ' . $name . ' ' . $ddl);
        }
    }
}

function jeab_ensure_inbox_klassning_log(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inbox_klassning_log (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                batch VARCHAR(40) NOT NULL,
                thread_id VARCHAR(64) NOT NULL,
                message_id VARCHAR(64) NOT NULL DEFAULT \'\',
                old_status VARCHAR(32) NOT NULL DEFAULT \'\',
                new_status VARCHAR(32) NOT NULL DEFAULT \'\',
                old_type VARCHAR(64) NOT NULL DEFAULT \'\',
                new_type VARCHAR(64) NOT NULL DEFAULT \'\',
                old_intent VARCHAR(32) NOT NULL DEFAULT \'\',
                new_intent VARCHAR(32) NOT NULL DEFAULT \'\',
                old_offer VARCHAR(32) NOT NULL DEFAULT \'\',
                new_offer VARCHAR(32) NOT NULL DEFAULT \'\',
                old_hook VARCHAR(64) NOT NULL DEFAULT \'\',
                new_hook VARCHAR(64) NOT NULL DEFAULT \'\',
                old_view VARCHAR(32) NOT NULL DEFAULT \'\',
                new_view VARCHAR(32) NOT NULL DEFAULT \'\',
                reminder_id INT NOT NULL DEFAULT 0,
                dry TINYINT(1) NOT NULL DEFAULT 0,
                undone TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY inbox_klassning_log_batch (batch)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inbox_klassning_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            batch TEXT NOT NULL,
            thread_id TEXT NOT NULL,
            message_id TEXT NOT NULL DEFAULT \'\',
            old_status TEXT NOT NULL DEFAULT \'\',
            new_status TEXT NOT NULL DEFAULT \'\',
            old_type TEXT NOT NULL DEFAULT \'\',
            new_type TEXT NOT NULL DEFAULT \'\',
            old_intent TEXT NOT NULL DEFAULT \'\',
            new_intent TEXT NOT NULL DEFAULT \'\',
            old_offer TEXT NOT NULL DEFAULT \'\',
            new_offer TEXT NOT NULL DEFAULT \'\',
            old_hook TEXT NOT NULL DEFAULT \'\',
            new_hook TEXT NOT NULL DEFAULT \'\',
            old_view TEXT NOT NULL DEFAULT \'\',
            new_view TEXT NOT NULL DEFAULT \'\',
            reminder_id INTEGER NOT NULL DEFAULT 0,
            dry INTEGER NOT NULL DEFAULT 0,
            undone INTEGER NOT NULL DEFAULT 0
        )'
    );
}

function jeab_ensure_inbox_reclassify_audit(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inbox_reclassify_audit (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                thread_id VARCHAR(64) NOT NULL,
                message_id VARCHAR(64) NOT NULL DEFAULT \'\',
                old_type VARCHAR(64) NOT NULL,
                new_type VARCHAR(64) NOT NULL,
                offer VARCHAR(32) NOT NULL DEFAULT \'\',
                dry TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inbox_reclassify_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            thread_id TEXT NOT NULL,
            message_id TEXT NOT NULL DEFAULT \'\',
            old_type TEXT NOT NULL,
            new_type TEXT NOT NULL,
            offer TEXT NOT NULL DEFAULT \'\',
            dry INTEGER NOT NULL DEFAULT 0
        )'
    );
}

/** Historik när kundens e-post byts. Idempotent. Ingen JSON-fil. */
function jeab_ensure_contact_email_audit(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS contact_email_audit (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                thread_id VARCHAR(64) NOT NULL DEFAULT \'\',
                reminder_id INT NOT NULL DEFAULT 0,
                old_thread VARCHAR(191) DEFAULT NULL,
                old_reminder VARCHAR(191) DEFAULT NULL,
                old_inbox TEXT,
                old_kontakt TEXT,
                email VARCHAR(191) NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS contact_email_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            thread_id TEXT NOT NULL DEFAULT \'\',
            reminder_id INTEGER NOT NULL DEFAULT 0,
            old_thread TEXT,
            old_reminder TEXT,
            old_inbox TEXT,
            old_kontakt TEXT,
            email TEXT NOT NULL
        )'
    );
}

/** list_status på inkorg_list så Historik kan sidindelas utan att läsa öppna rader. */
/** Mejlens updated_at så revisionen flyttar vid varje skrivning, inte bara ny received_at. */
function jeab_inkorg_ensure_messages_updated_at(PDO $pdo, string $driver): void
{
    if (jeab_pdo_tx_busy($pdo)) {
        return;
    }
    $flag = 'inkorg_messages_updated_at_v1';
    if (jeab_schema_flag_ok(jeab_settings_value($pdo, $driver, $flag))) {
        return;
    }
    if (jeab_schema_request_skips_ddl()) {
        return;
    }
    jeab_ensure_table_columns($pdo, 'inkorg_messages', [
        'updated_at' => $driver === 'mysql' ? 'DATETIME DEFAULT NULL' : 'TEXT',
    ], $driver);
    jeab_settings_put($pdo, $driver, $flag, 'ok');
}

function jeab_inkorg_ensure_list_status(PDO $pdo, string $driver): void
{
    if (jeab_pdo_tx_busy($pdo)) {
        return;
    }
    $flag = 'inkorg_list_status_v1';
    if (jeab_schema_ddl_blocked($pdo, $driver, $flag)) {
        return;
    }
    jeab_ensure_table_columns($pdo, 'inkorg_list', [
        'list_status' => $driver === 'mysql' ? "VARCHAR(32) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''",
    ], $driver);
    $name = 'inkorg_list_company_status_at';
    try {
        if (!in_array($name, jeab_existing_index_names($pdo, 'inkorg_list', $driver), true)) {
            jeab_list_index_create($pdo, jeab_create_index_sql($driver, $name, 'inkorg_list (company, list_status, list_at)'));
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        if (stripos($message, 'Duplicate') === false && stripos($message, 'already exists') === false) {
            if (function_exists('jeab_log_app_error')) {
                jeab_log_app_error('db', 'CREATE INDEX misslyckades: ' . $name);
            }
            jeab_settings_put($pdo, $driver, $flag, 'fail:' . $name);

            return;
        }
    }
    jeab_settings_put($pdo, $driver, $flag, 'ok');
}

/**
 * contact_id på inkorg_list. Kolumn, index och påfyllnad hör till tick och migrate.
 * En sidvisning gör inget av det och läser inte schemat. v1 fyllde bara tomma
 * kolumner. v2 rättar också en kolumn som pekar på en annan kontakt än raden.
 * Båda flaggorna sätts bara när kolumn, index och påfyllnad lyckades.
 * Påfyllnaden läser id som redan ligger i raden. Ingen ny JSON-fil.
 */
function jeab_inkorg_ensure_list_contact(PDO $pdo, string $driver): bool
{
    static $settled = null;
    $settled ??= new WeakMap();
    if (isset($settled[$pdo])) {
        return true;
    }
    if (jeab_pdo_tx_busy($pdo)) {
        return false;
    }
    $flag = 'inkorg_list_contact_v2';
    if (jeab_schema_flag_ok(jeab_settings_value($pdo, $driver, $flag))) {
        $settled[$pdo] = true;

        return true;
    }
    if (jeab_schema_request_skips_ddl()) {
        return false;
    }
    $GLOBALS['_JEAB_INKORG_LIST_CONTACT_DDL'] = (int) ($GLOBALS['_JEAB_INKORG_LIST_CONTACT_DDL'] ?? 0) + 1;
    jeab_ensure_table_columns($pdo, 'inkorg_list', [
        'contact_id' => $driver === 'mysql' ? "VARCHAR(64) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''",
    ], $driver);
    $index = 'inkorg_list_contact';
    try {
        if (!in_array($index, jeab_existing_index_names($pdo, 'inkorg_list', $driver), true)) {
            jeab_list_index_create($pdo, jeab_create_index_sql($driver, $index, 'inkorg_list (contact_id)'));
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        if (stripos($message, 'Duplicate') === false && stripos($message, 'already exists') === false) {
            if (function_exists('jeab_log_app_error')) {
                jeab_log_app_error('db', 'CREATE INDEX misslyckades: ' . $index);
            }
            jeab_settings_put($pdo, $driver, $flag, 'fail:' . $index);

            return false;
        }
    }
    try {
        if ($driver === 'mysql') {
            $pdo->exec(
                'UPDATE inkorg_list SET contact_id = LEFT(JSON_UNQUOTE(JSON_EXTRACT(row_json, \'$.contact_id\')), 64)
                 WHERE JSON_UNQUOTE(JSON_EXTRACT(row_json, \'$.contact_id\')) IS NOT NULL
                   AND JSON_UNQUOTE(JSON_EXTRACT(row_json, \'$.contact_id\')) <> \'\'
                   AND JSON_UNQUOTE(JSON_EXTRACT(row_json, \'$.contact_id\')) <> \'null\'
                   AND (contact_id = \'\' OR contact_id <> LEFT(JSON_UNQUOTE(JSON_EXTRACT(row_json, \'$.contact_id\')), 64))'
            );
        } else {
            $pdo->exec(
                'UPDATE inkorg_list SET contact_id = substr(json_extract(row_json, \'$.contact_id\'), 1, 64)
                 WHERE json_extract(row_json, \'$.contact_id\') IS NOT NULL
                   AND json_extract(row_json, \'$.contact_id\') <> \'\'
                   AND json_extract(row_json, \'$.contact_id\') <> \'null\'
                   AND (contact_id IS NULL OR contact_id = \'\' OR contact_id <> substr(json_extract(row_json, \'$.contact_id\'), 1, 64))'
            );
        }
    } catch (Throwable $e) {
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('db', 'inkorg_list contact backfill');
        }
        unset($e);
        jeab_settings_put($pdo, $driver, $flag, 'fail:backfill');

        return false;
    }
    if (!jeab_settings_put($pdo, $driver, 'inkorg_list_contact_v1', 'ok')) {
        return false;
    }
    if (!jeab_settings_put($pdo, $driver, $flag, 'ok')) {
        return false;
    }
    $settled[$pdo] = true;

    return true;
}

/**
 * Tabeller för en ny databas eller sqlite-reserven. Inga extra index.
 * Anropas från en sidvisning när tabellflaggan saknas.
 */
function jeab_inkorg_create_request_tables(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_messages (
                id VARCHAR(191) NOT NULL,
                thread_id VARCHAR(191) NOT NULL DEFAULT \'\',
                received_at DATETIME DEFAULT NULL,
                from_addr VARCHAR(191) NOT NULL DEFAULT \'\',
                is_note TINYINT(1) NOT NULL DEFAULT 0,
                updated_at DATETIME DEFAULT NULL,
                row_json LONGTEXT NOT NULL,
                PRIMARY KEY (id),
                KEY inkorg_messages_thread_id (thread_id),
                KEY inkorg_messages_received_at (received_at),
                KEY inkorg_messages_from_addr (from_addr),
                KEY inkorg_messages_is_note (is_note)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_threads (
                id VARCHAR(191) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT \'open\',
                updated_at DATETIME DEFAULT NULL,
                row_json LONGTEXT NOT NULL,
                PRIMARY KEY (id),
                KEY inkorg_threads_status (status),
                KEY inkorg_threads_updated_at (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_list (
                id VARCHAR(191) NOT NULL,
                company VARCHAR(32) NOT NULL DEFAULT \'\',
                customer_email VARCHAR(191) NOT NULL DEFAULT \'\',
                list_at VARCHAR(40) NOT NULL DEFAULT \'\',
                list_status VARCHAR(32) NOT NULL DEFAULT \'\',
                contact_id VARCHAR(64) NOT NULL DEFAULT \'\',
                row_json LONGTEXT NOT NULL,
                PRIMARY KEY (id),
                KEY inkorg_list_company_at (company, list_at),
                KEY inkorg_list_customer (customer_email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_count_cache (
                cache_key VARCHAR(191) NOT NULL,
                stored_at INT NOT NULL DEFAULT 0,
                payload LONGTEXT NOT NULL,
                PRIMARY KEY (cache_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inkorg_messages (
            id TEXT PRIMARY KEY,
            thread_id TEXT NOT NULL DEFAULT \'\',
            received_at TEXT,
            from_addr TEXT NOT NULL DEFAULT \'\',
            is_note INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT,
            row_json TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inkorg_threads (
            id TEXT PRIMARY KEY,
            status TEXT NOT NULL DEFAULT \'open\',
            updated_at TEXT,
            row_json TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inkorg_list (
            id TEXT PRIMARY KEY,
            company TEXT NOT NULL DEFAULT \'\',
            customer_email TEXT NOT NULL DEFAULT \'\',
            list_at TEXT NOT NULL DEFAULT \'\',
            list_status TEXT NOT NULL DEFAULT \'\',
            contact_id TEXT NOT NULL DEFAULT \'\',
            row_json TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inkorg_count_cache (
            cache_key TEXT PRIMARY KEY,
            stored_at INTEGER NOT NULL DEFAULT 0,
            payload TEXT NOT NULL
        )'
    );
}

/** Sant när varje del har ok, inte fail:. */
function jeab_inkorg_ddl_parts_ok(PDO $pdo, string $driver): bool
{
    foreach ([
        'inkorg_schema_v2',
        'inkorg_list_status_v1',
        'inkorg_list_contact_v1',
        'inkorg_list_contact_v2',
        'inkorg_messages_updated_at_v1',
        'inkorg_import_cache_v1',
        'inkorg_open_indexes_v1',
        'hs_import_ddl_v1',
    ] as $key) {
        if (!jeab_schema_flag_ok(jeab_settings_value($pdo, $driver, $key))) {
            return false;
        }
    }

    return true;
}

/**
 * Malmö-importens lista. Ingen JSON-fil.
 * Flaggan inkorg_import_cache_v1 hoppar över DDL på senare anrop.
 */
function jeab_inkorg_ensure_import_cache(PDO $pdo, string $driver): void
{
    static $settled = null;
    $settled ??= new WeakMap();
    if (isset($settled[$pdo])) {
        return;
    }
    if (jeab_pdo_tx_busy($pdo)) {
        return;
    }
    $flag = 'inkorg_import_cache_v1';
    if (jeab_settings_value($pdo, $driver, $flag) === 'ok') {
        $settled[$pdo] = true;

        return;
    }
    $GLOBALS['_JEAB_INKORG_IMPORT_CACHE_DDL'] = (int) ($GLOBALS['_JEAB_INKORG_IMPORT_CACHE_DDL'] ?? 0) + 1;
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_import_cache (
                cache_key VARCHAR(64) NOT NULL,
                payload LONGTEXT NOT NULL,
                PRIMARY KEY (cache_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_import_cache (
                cache_key TEXT PRIMARY KEY,
                payload TEXT NOT NULL
            )'
        );
    }
    if (!jeab_settings_put($pdo, $driver, $flag, 'ok')) {
        return;
    }
    $settled[$pdo] = true;
}

/** Inkorg meddelanden, anteckningar och trådar. Idempotent. schema/ deployas inte. */
function jeab_ensure_inkorg_tables(PDO $pdo, string $driver): void
{
    static $settled = null;
    $settled ??= new WeakMap();
    if (isset($settled[$pdo])) {
        return;
    }
    if (jeab_pdo_tx_busy($pdo)) {
        return;
    }
    // Delarna har egna flaggor. inkorg_ddl_v3 blir ok bara när alla är ok.
    // En sidvisning skapar tabellerna om de saknas, men inte index eller påfyllnad.
    $page = jeab_schema_request_skips_ddl();
    $flag = 'inkorg_schema_v2';
    $schemaOk = jeab_settings_value($pdo, $driver, $flag) === 'ok';
    $tablesOk = $schemaOk || jeab_settings_value($pdo, $driver, 'inkorg_tables_v1') === 'ok';
    if (!$schemaOk && $page) {
        if (!$tablesOk) {
            jeab_inkorg_create_request_tables($pdo, $driver);
            jeab_settings_put($pdo, $driver, 'inkorg_tables_v1', 'ok');
        }
    } elseif (!$schemaOk) {
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_messages (
                id VARCHAR(191) NOT NULL,
                thread_id VARCHAR(191) NOT NULL DEFAULT \'\',
                received_at DATETIME DEFAULT NULL,
                from_addr VARCHAR(191) NOT NULL DEFAULT \'\',
                is_note TINYINT(1) NOT NULL DEFAULT 0,
                updated_at DATETIME DEFAULT NULL,
                row_json LONGTEXT NOT NULL,
                PRIMARY KEY (id),
                KEY inkorg_messages_thread_id (thread_id),
                KEY inkorg_messages_received_at (received_at),
                KEY inkorg_messages_from_addr (from_addr),
                KEY inkorg_messages_is_note (is_note)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_threads (
                id VARCHAR(191) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT \'open\',
                updated_at DATETIME DEFAULT NULL,
                row_json LONGTEXT NOT NULL,
                PRIMARY KEY (id),
                KEY inkorg_threads_status (status),
                KEY inkorg_threads_updated_at (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_list (
                id VARCHAR(191) NOT NULL,
                company VARCHAR(32) NOT NULL DEFAULT \'\',
                customer_email VARCHAR(191) NOT NULL DEFAULT \'\',
                list_at VARCHAR(40) NOT NULL DEFAULT \'\',
                list_status VARCHAR(32) NOT NULL DEFAULT \'\',
                contact_id VARCHAR(64) NOT NULL DEFAULT \'\',
                row_json LONGTEXT NOT NULL,
                PRIMARY KEY (id),
                KEY inkorg_list_company_at (company, list_at),
                KEY inkorg_list_customer (customer_email),
                KEY inkorg_list_contact (contact_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_count_cache (
                cache_key VARCHAR(191) NOT NULL,
                stored_at INT NOT NULL DEFAULT 0,
                payload LONGTEXT NOT NULL,
                PRIMARY KEY (cache_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_messages (
                id TEXT PRIMARY KEY,
                thread_id TEXT NOT NULL DEFAULT \'\',
                received_at TEXT,
                from_addr TEXT NOT NULL DEFAULT \'\',
                is_note INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT,
                row_json TEXT NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_threads (
                id TEXT PRIMARY KEY,
                status TEXT NOT NULL DEFAULT \'open\',
                updated_at TEXT,
                row_json TEXT NOT NULL
            )'
        );
        jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS inkorg_messages_thread_id ON inkorg_messages (thread_id)');
        jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS inkorg_messages_received_at ON inkorg_messages (received_at)');
        jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS inkorg_messages_from_addr ON inkorg_messages (from_addr)');
        jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS inkorg_messages_is_note ON inkorg_messages (is_note)');
        jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS inkorg_threads_status ON inkorg_threads (status)');
        jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS inkorg_threads_updated_at ON inkorg_threads (updated_at)');
        jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS inkorg_messages_thread_received ON inkorg_messages (thread_id, received_at)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_list (
                id TEXT PRIMARY KEY,
                company TEXT NOT NULL DEFAULT \'\',
                customer_email TEXT NOT NULL DEFAULT \'\',
                list_at TEXT NOT NULL DEFAULT \'\',
                list_status TEXT NOT NULL DEFAULT \'\',
                contact_id TEXT NOT NULL DEFAULT \'\',
                row_json TEXT NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_count_cache (
                cache_key TEXT PRIMARY KEY,
                stored_at INTEGER NOT NULL DEFAULT 0,
                payload TEXT NOT NULL
            )'
        );
        jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS inkorg_list_company_at ON inkorg_list (company, list_at)');
        if (jeab_copy_column_present($pdo, 'inkorg_list', 'customer_email')) {
            jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS inkorg_list_customer ON inkorg_list (customer_email)');
        }
        jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS inkorg_list_company_status_at ON inkorg_list (company, list_status, list_at)');
    }
    jeab_ensure_table_columns($pdo, 'inkorg_messages', [
        'thread_id' => $driver === 'mysql' ? 'VARCHAR(191) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\'',
        'received_at' => $driver === 'mysql' ? 'DATETIME DEFAULT NULL' : 'TEXT',
        'from_addr' => $driver === 'mysql' ? 'VARCHAR(191) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\'',
        'is_note' => $driver === 'mysql' ? 'TINYINT(1) NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0',
        'updated_at' => $driver === 'mysql' ? 'DATETIME DEFAULT NULL' : 'TEXT',
        'row_json' => $driver === 'mysql' ? 'LONGTEXT NOT NULL' : 'TEXT NOT NULL',
    ], $driver);
    foreach ([
        'inkorg_messages_thread_id' => 'CREATE INDEX inkorg_messages_thread_id ON inkorg_messages (thread_id)',
        'inkorg_messages_received_at' => 'CREATE INDEX inkorg_messages_received_at ON inkorg_messages (received_at)',
        'inkorg_messages_from_addr' => 'CREATE INDEX inkorg_messages_from_addr ON inkorg_messages (from_addr)',
        'inkorg_messages_is_note' => 'CREATE INDEX inkorg_messages_is_note ON inkorg_messages (is_note)',
        'inkorg_threads_status' => 'CREATE INDEX inkorg_threads_status ON inkorg_threads (status)',
        'inkorg_threads_updated_at' => 'CREATE INDEX inkorg_threads_updated_at ON inkorg_threads (updated_at)',
        'inkorg_messages_thread_received' => 'CREATE INDEX inkorg_messages_thread_received ON inkorg_messages (thread_id, received_at)',
        'inkorg_list_company_at' => 'CREATE INDEX inkorg_list_company_at ON inkorg_list (company, list_at)',
        'inkorg_list_customer' => 'CREATE INDEX inkorg_list_customer ON inkorg_list (customer_email)',
        'inkorg_list_company_status_at' => 'CREATE INDEX inkorg_list_company_status_at ON inkorg_list (company, list_status, list_at)',
    ] as $name => $sql) {
        $table = 'inkorg_messages';
        if (str_starts_with($name, 'inkorg_threads')) {
            $table = 'inkorg_threads';
        } elseif (str_starts_with($name, 'inkorg_list')) {
            $table = 'inkorg_list';
        }
        if ($name === 'inkorg_list_customer' && !jeab_copy_column_present($pdo, 'inkorg_list', 'customer_email')) {
            // contacts.drop_customer_fields tog bort kolumnen. Indexet kommer tillbaka med undo.
            continue;
        }
        if (!in_array($name, jeab_existing_index_names($pdo, $table, $driver), true)) {
            jeab_list_index_create($pdo, $sql);
        }
    }
    jeab_settings_put($pdo, $driver, 'inkorg_tables_v1', 'ok');
    jeab_settings_put($pdo, $driver, $flag, 'ok');
    }
    jeab_inkorg_ensure_list_status($pdo, $driver);
    jeab_inkorg_ensure_list_contact($pdo, $driver);
    jeab_inkorg_ensure_messages_updated_at($pdo, $driver);
    jeab_inkorg_ensure_import_cache($pdo, $driver);
    jeab_inkorg_ensure_open_indexes($pdo, $driver);
    jeab_inkorg_ensure_hs_list_page($pdo);
    if (!$page && jeab_inkorg_ddl_parts_ok($pdo, $driver)) {
        jeab_settings_put($pdo, $driver, 'inkorg_ddl_v3', 'ok');
    }
    $settled[$pdo] = true;
}

/**
 * Index för att öppna ett ärende. received_at är meddelandets datum.
 * id på tråden är primärnyckel. Flaggan inkorg_open_indexes_v1 hoppar över
 * SHOW INDEX och CREATE när indexen redan är försökta. MySQL får CREATE INDEX
 * utan IF NOT EXISTS.
 *
 * @return bool false när ett index inte gick att skapa
 */
function jeab_inkorg_ensure_open_indexes(PDO $pdo, string $driver): bool
{
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return false;
    }
    $flag = 'inkorg_open_indexes_v1';
    if (jeab_schema_ddl_blocked($pdo, $driver, $flag)) {
        return jeab_schema_flag_ok(jeab_settings_value($pdo, $driver, $flag));
    }
    $wanted = [
        'inkorg_messages_thread_id' => ['inkorg_messages', 'thread_id'],
        'inkorg_messages_thread_date' => ['inkorg_messages', 'thread_id, received_at'],
        'inkorg_threads_status' => ['inkorg_threads', 'status'],
        'inkorg_threads_status_at' => ['inkorg_threads', 'status, updated_at'],
        'inkorg_threads_id_status' => ['inkorg_threads', 'id, status'],
    ];
    $failed = [];
    $byTable = [];
    foreach ($wanted as $name => [$table, $cols]) {
        if (!isset($byTable[$table])) {
            try {
                $byTable[$table] = jeab_existing_index_names($pdo, $table, $driver);
            } catch (Throwable) {
                return false;
            }
        }
        if (in_array($name, $byTable[$table], true)) {
            continue;
        }
        try {
            jeab_list_index_create($pdo, jeab_create_index_sql($driver, $name, $table . ' (' . $cols . ')'));
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (stripos($message, 'Duplicate') !== false || stripos($message, 'already exists') !== false) {
                continue;
            }
            $failed[] = $name . ': ' . $message;
        }
    }
    if ($failed === []) {
        jeab_settings_put($pdo, $driver, $flag, 'ok');

        return true;
    }
    $note = implode('; ', $failed);
    if (function_exists('jeab_log_app_error')) {
        jeab_log_app_error('db', 'CREATE INDEX misslyckades: ' . $note);
    }
    jeab_settings_put($pdo, $driver, $flag, 'fail:' . $note);

    return false;
}

/** Help Scout-listans sidindex. Samma väg som övriga inkorgsindex när schemaflaggan redan är satt. */
function jeab_inkorg_ensure_hs_list_page(PDO $pdo): void
{
    $path = __DIR__ . '/hs_import_store.php';
    if (is_file($path)) {
        require_once $path;
    }
    if (function_exists('jeab_hs_import_store_ensure')) {
        jeab_hs_import_store_ensure($pdo);
    }
    if (function_exists('jeab_hs_import_ensure_list_page')) {
        jeab_hs_import_ensure_list_page($pdo);
    }
}

/** Kontakter och inkorgens contacts.json. Idempotent. schema/ deployas inte. */
function jeab_ensure_contacts_tables(PDO $pdo, string $driver): void
{
    static $settled = null;
    $settled ??= new WeakMap();
    if (isset($settled[$pdo])) {
        jeab_ensure_contact_emails($pdo, $driver);
        jeab_ensure_contact_secondary_columns($pdo, $driver);

        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    $schemaFlag = 'contacts_schema_v1';
    if (jeab_settings_value($pdo, $driver, $schemaFlag) === 'ok') {
        $settled[$pdo] = true;
        jeab_ensure_contact_emails($pdo, $driver);
        jeab_ensure_contact_secondary_columns($pdo, $driver);

        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS contacts (
                id VARCHAR(32) NOT NULL,
                row_json LONGTEXT NOT NULL,
                street_key VARCHAR(191) NOT NULL DEFAULT \'\',
                postal_code VARCHAR(16) NOT NULL DEFAULT \'\',
                owner_email VARCHAR(191) NOT NULL DEFAULT \'\',
                owner_phone VARCHAR(32) NOT NULL DEFAULT \'\',
                owner_name_fold VARCHAR(191) NOT NULL DEFAULT \'\',
                email2 VARCHAR(191) NOT NULL DEFAULT \'\',
                email3 VARCHAR(191) NOT NULL DEFAULT \'\',
                company VARCHAR(32) NOT NULL DEFAULT \'\',
                city_fold VARCHAR(191) NOT NULL DEFAULT \'\',
                sort_key VARCHAR(191) NOT NULL DEFAULT \'\',
                sort_city VARCHAR(191) NOT NULL DEFAULT \'\',
                list_ready TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY contacts_street_key (street_key),
                KEY contacts_postal_code (postal_code),
                KEY contacts_owner_email (owner_email),
                KEY contacts_owner_phone (owner_phone),
                KEY contacts_owner_name_fold (owner_name_fold),
                KEY contacts_company (company),
                KEY contacts_city_fold (city_fold),
                KEY contacts_sort_key (sort_key),
                KEY contacts_list_ready (list_ready)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_contacts (
                id VARCHAR(64) NOT NULL,
                row_json LONGTEXT NOT NULL,
                email VARCHAR(191) NOT NULL DEFAULT \'\',
                mobile VARCHAR(32) NOT NULL DEFAULT \'\',
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY inkorg_contacts_email (email),
                KEY inkorg_contacts_mobile (mobile)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS contacts (
                id TEXT PRIMARY KEY,
                row_json TEXT NOT NULL,
                street_key TEXT NOT NULL DEFAULT \'\',
                postal_code TEXT NOT NULL DEFAULT \'\',
                owner_email TEXT NOT NULL DEFAULT \'\',
                owner_phone TEXT NOT NULL DEFAULT \'\',
                owner_name_fold TEXT NOT NULL DEFAULT \'\',
                email2 TEXT NOT NULL DEFAULT \'\',
                email3 TEXT NOT NULL DEFAULT \'\',
                company TEXT NOT NULL DEFAULT \'\',
                city_fold TEXT NOT NULL DEFAULT \'\',
                sort_key TEXT NOT NULL DEFAULT \'\',
                sort_city TEXT NOT NULL DEFAULT \'\',
                list_ready INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_contacts (
                id TEXT PRIMARY KEY,
                row_json TEXT NOT NULL,
                email TEXT NOT NULL DEFAULT \'\',
                mobile TEXT NOT NULL DEFAULT \'\',
                updated_at TEXT NOT NULL
            )'
        );
        jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS contacts_street_key ON contacts (street_key)');
        jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS contacts_postal_code ON contacts (postal_code)');
        jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS contacts_owner_email ON contacts (owner_email)');
        jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS contacts_owner_phone ON contacts (owner_phone)');
        jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS contacts_owner_name_fold ON contacts (owner_name_fold)');
        jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS inkorg_contacts_email ON inkorg_contacts (email)');
        jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS inkorg_contacts_mobile ON inkorg_contacts (mobile)');
    }
    jeab_ensure_table_columns($pdo, 'contacts', [
        'street_key' => $driver === 'mysql' ? 'VARCHAR(191) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\'',
        'postal_code' => $driver === 'mysql' ? 'VARCHAR(16) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\'',
        'owner_email' => $driver === 'mysql' ? 'VARCHAR(191) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\'',
        'owner_phone' => $driver === 'mysql' ? 'VARCHAR(32) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\'',
        'owner_name_fold' => $driver === 'mysql' ? 'VARCHAR(191) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\'',
        'email2' => $driver === 'mysql' ? 'VARCHAR(191) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\'',
        'email3' => $driver === 'mysql' ? 'VARCHAR(191) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\'',
        'company' => $driver === 'mysql' ? 'VARCHAR(32) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\'',
        'city_fold' => $driver === 'mysql' ? 'VARCHAR(191) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\'',
        'sort_key' => $driver === 'mysql' ? 'VARCHAR(191) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\'',
        'sort_city' => $driver === 'mysql' ? 'VARCHAR(191) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\'',
        'list_ready' => $driver === 'mysql' ? 'TINYINT(1) NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0',
        'row_json' => $driver === 'mysql' ? 'LONGTEXT NOT NULL' : 'TEXT NOT NULL',
        'created_at' => $driver === 'mysql' ? 'DATETIME NOT NULL' : 'TEXT NOT NULL',
        'updated_at' => $driver === 'mysql' ? 'DATETIME NOT NULL' : 'TEXT NOT NULL',
    ], $driver);
    jeab_ensure_table_columns($pdo, 'inkorg_contacts', [
        'email' => $driver === 'mysql' ? 'VARCHAR(191) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\'',
        'mobile' => $driver === 'mysql' ? 'VARCHAR(32) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\'',
        'row_json' => $driver === 'mysql' ? 'LONGTEXT NOT NULL' : 'TEXT NOT NULL',
        'updated_at' => $driver === 'mysql' ? 'DATETIME NOT NULL' : 'TEXT NOT NULL',
    ], $driver);
    foreach ([
        'contacts' => [
            'contacts_street_key' => 'CREATE INDEX contacts_street_key ON contacts (street_key)',
            'contacts_postal_code' => 'CREATE INDEX contacts_postal_code ON contacts (postal_code)',
            'contacts_owner_email' => 'CREATE INDEX contacts_owner_email ON contacts (owner_email)',
            'contacts_owner_phone' => 'CREATE INDEX contacts_owner_phone ON contacts (owner_phone)',
            'contacts_owner_name_fold' => 'CREATE INDEX contacts_owner_name_fold ON contacts (owner_name_fold)',
            'contacts_company' => 'CREATE INDEX contacts_company ON contacts (company)',
            'contacts_city_fold' => 'CREATE INDEX contacts_city_fold ON contacts (city_fold)',
            'contacts_sort_key' => 'CREATE INDEX contacts_sort_key ON contacts (sort_key)',
            'contacts_list_ready' => 'CREATE INDEX contacts_list_ready ON contacts (list_ready)',
        ],
        'inkorg_contacts' => [
            'inkorg_contacts_email' => 'CREATE INDEX inkorg_contacts_email ON inkorg_contacts (email)',
            'inkorg_contacts_mobile' => 'CREATE INDEX inkorg_contacts_mobile ON inkorg_contacts (mobile)',
        ],
    ] as $table => $indexes) {
        $have = jeab_existing_index_names($pdo, $table, $driver);
        foreach ($indexes as $name => $sql) {
            if (!in_array($name, $have, true)) {
                jeab_list_index_create($pdo, $sql);
            }
        }
    }
    jeab_ensure_contact_conflict_undo($pdo, $driver);
    jeab_ensure_contacts_sole_undo($pdo, $driver);
    jeab_contact_emails_create_table($pdo, $driver);
    if (!jeab_settings_put($pdo, $driver, $schemaFlag, 'ok')) {
        return;
    }
    $settled[$pdo] = true;
    jeab_ensure_contact_emails($pdo, $driver);
    jeab_ensure_contact_secondary_columns($pdo, $driver);
}

/**
 * email2 och email3 på contacts. Idempotent: kolumnen läggs bara om den saknas.
 * Tom sträng är tillåten. Indexen används av inkorgsmatchningen.
 */
function jeab_ensure_contact_secondary_columns(PDO $pdo, string $driver): void
{
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if (function_exists('jeab_schema_probe_allowed') && !jeab_schema_probe_allowed()) {
        return;
    }
    static $done = [];
    $id = spl_object_id($pdo);
    if (isset($done[$id])) {
        return;
    }
    try {
        $pdo->query('SELECT 1 FROM contacts LIMIT 0');
    } catch (Throwable) {
        return;
    }
    $driver = $driver === 'mysql' ? 'mysql' : 'sqlite';
    $type = $driver === 'mysql' ? 'VARCHAR(191) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\'';
    try {
        jeab_ensure_table_columns($pdo, 'contacts', [
            'email2' => $type,
            'email3' => $type,
        ], $driver);
    } catch (Throwable) {
        return;
    }
    $GLOBALS['_JEAB_CONTACT_SECONDARY_GEN'] = (int) ($GLOBALS['_JEAB_CONTACT_SECONDARY_GEN'] ?? 0) + 1;
    try {
        $have = jeab_existing_index_names($pdo, 'contacts', $driver);
    } catch (Throwable) {
        $done[$id] = true;

        return;
    }
    foreach ([
        'contacts_email2' => 'contacts (email2)',
        'contacts_email3' => 'contacts (email3)',
    ] as $name => $on) {
        if (in_array($name, $have, true)) {
            continue;
        }
        try {
            jeab_list_index_create($pdo, jeab_create_index_sql($driver, $name, $on));
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (stripos($message, 'Duplicate') === false && stripos($message, 'already exists') === false) {
                continue;
            }
        }
    }
    $done[$id] = true;
}

/**
 * Prefixindex för kontaktsök på telefon och e-post.
 * Tick och migrate skapar dem. En sidvisning gör inget, även när flaggan saknas.
 * MySQL får vänsterprefix utan IF NOT EXISTS. SQLite indexerar hela kolumnen.
 */
function jeab_ensure_contact_search_prefix(PDO $pdo, string $driver): void
{
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    $flag = 'contacts_search_prefix_v1';
    if (jeab_schema_ddl_blocked($pdo, $driver, $flag)) {
        return;
    }
    $specs = $driver === 'mysql'
        ? [
            'contacts_email_prefix' => 'contacts (owner_email(64))',
            'contacts_phone_prefix' => 'contacts (owner_phone(16))',
        ]
        : [
            'contacts_email_prefix' => 'contacts (owner_email)',
            'contacts_phone_prefix' => 'contacts (owner_phone)',
        ];
    try {
        $have = jeab_existing_index_names($pdo, 'contacts', $driver);
    } catch (Throwable) {
        return;
    }
    $failed = [];
    foreach ($specs as $name => $on) {
        if (in_array($name, $have, true)) {
            continue;
        }
        $column = $name === 'contacts_email_prefix' ? 'owner_email' : 'owner_phone';
        if (!jeab_index_column_ok($pdo, 'contacts', $column, $driver)) {
            $failed[] = $name;
            continue;
        }
        try {
            jeab_list_index_create($pdo, jeab_create_index_sql($driver, $name, $on));
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (stripos($message, 'Duplicate') !== false || stripos($message, 'already exists') !== false) {
                continue;
            }
            $failed[] = $name;
        }
    }
    if ($failed !== []) {
        jeab_settings_put($pdo, $driver, $flag, 'fail:' . implode(',', $failed));
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('db', 'CREATE INDEX misslyckades: ' . implode(',', $failed), [
                'level' => 'info',
                'dedupe_seconds' => 3600,
                'skip_request' => true,
            ]);
        }

        return;
    }
    jeab_settings_put($pdo, $driver, $flag, 'ok');
}

/**
 * Tabellen skapas av tick och migrate, även när contacts_schema_v1 redan är ok.
 * Ett kontorsklick och en POST skapar den inte.
 */
function jeab_contact_emails_ddl_allowed(): bool
{
    // _JEAB_MIGRATE_RUNS ligger kvar på arbetaren efter första migrationen.
    // Bara tick och själva migreringsanropet får skapa tabellen.
    return !empty($GLOBALS['_JEAB_TICK'])
        || !empty($GLOBALS['_JEAB_SCHEMA_DDL'])
        || !empty($GLOBALS['_JEAB_SCHEMA_MIGRATING']);
}

function jeab_contact_emails_create_table(PDO $pdo, string $driver): void
{
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS contact_emails (
                email VARCHAR(191) NOT NULL,
                contact_id VARCHAR(32) NOT NULL,
                PRIMARY KEY (email, contact_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS contact_emails (
                email TEXT NOT NULL,
                contact_id TEXT NOT NULL,
                PRIMARY KEY (email, contact_id)
            )'
        );
    }
    jeab_contact_emails_table_exists($pdo, true);
}

/** Sant när tabellen går att läsa. Skapar den inte. En träff gäller anropet. */
function jeab_contact_emails_table_exists(PDO $pdo, ?bool $known = null): bool
{
    static $seen = [];
    $key = spl_object_id($pdo);
    $stamp = (string) ($_SERVER['REQUEST_TIME_FLOAT'] ?? '1');
    if ($known !== null) {
        $seen[$key] = ['stamp' => $stamp, 'ok' => $known];

        return $known;
    }
    if (isset($seen[$key]) && (string) ($seen[$key]['stamp'] ?? '') === $stamp) {
        return !empty($seen[$key]['ok']);
    }
    try {
        $pdo->query('SELECT 1 FROM contact_emails LIMIT 0');
        $ok = true;
    } catch (Throwable) {
        $ok = false;
    }
    $seen[$key] = ['stamp' => $stamp, 'ok' => $ok];

    return $ok;
}

/**
 * Adressindex för klassningen. 400 kontakter per anrop, med cursor i settings,
 * så ett avbrutet anrop fortsätter. GET_LOCK och hoppa över om låset är taget.
 * Saknas tabellen fylls inget. Tick och migrate skapar den först.
 */
function jeab_ensure_contact_emails(PDO $pdo, string $driver): void
{
    if (jeab_contact_emails_ddl_allowed()) {
        try {
            jeab_contact_emails_create_table($pdo, $driver);
        } catch (Throwable) {
            return;
        }
    }
    static $ran = [];
    $key = spl_object_id($pdo);
    $stamp = (string) ($_SERVER['REQUEST_TIME_FLOAT'] ?? '1');
    if (($ran[$key] ?? '') === $stamp) {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if (jeab_settings_value($pdo, $driver, 'contact_emails_v1') === 'ok') {
        $ran[$key] = $stamp;

        return;
    }
    if (!jeab_contact_emails_table_exists($pdo)) {
        $ran[$key] = $stamp;

        return;
    }
    if (!function_exists('jeab_contact_email_list')) {
        return;
    }
    if (!jeab_db_get_lock($pdo, 'jeab_contact_emails')) {
        $ran[$key] = $stamp;

        return;
    }
    $ran[$key] = $stamp;
    try {
        $cursor = (string) (jeab_settings_value($pdo, $driver, 'contact_emails_cursor') ?? '');
        $stmt = $pdo->prepare('SELECT id, row_json FROM contacts WHERE id > ? ORDER BY id LIMIT 400');
        $stmt->execute([$cursor]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }
        jeab_pdo_transaction($pdo, static function () use ($pdo, $driver, $rows, $cursor): void {
            $del = $pdo->prepare('DELETE FROM contact_emails WHERE contact_id = ?');
            $ins = $pdo->prepare('INSERT INTO contact_emails (email, contact_id) VALUES (?, ?)');
            $last = $cursor;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $decoded = json_decode((string) ($row['row_json'] ?? ''), true);
                $id = (string) ($row['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $last = $id;
                if (!is_array($decoded)) {
                    continue;
                }
                $del->execute([$id]);
                foreach (jeab_contact_email_list($decoded) as $email) {
                    $ins->execute([mb_substr($email, 0, 191), $id]);
                }
            }
            if (!jeab_settings_put($pdo, $driver, 'contact_emails_cursor', $last)) {
                throw new RuntimeException('contact_emails cursor');
            }
            if (count($rows) < 400 && !jeab_settings_put($pdo, $driver, 'contact_emails_v1', 'ok')) {
                throw new RuntimeException('contact_emails flag');
            }
        });
    } catch (Throwable) {
    } finally {
        jeab_db_release_lock($pdo, 'jeab_contact_emails');
    }
}

/** Bakgrundsjobb för data.migrate_all och kontakter.conflicts. */
function jeab_ensure_data_jobs(PDO $pdo, string $driver): void
{
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS data_jobs (
                job_id VARCHAR(64) NOT NULL,
                job_type VARCHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL,
                progress_json LONGTEXT NOT NULL,
                state_json LONGTEXT NOT NULL,
                result_json LONGTEXT NULL,
                payload_json LONGTEXT NOT NULL,
                heartbeat_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (job_id),
                KEY data_jobs_type_status (job_type, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS data_job_locks (
                job_type VARCHAR(64) NOT NULL,
                job_id VARCHAR(64) NOT NULL,
                heartbeat_at DATETIME NOT NULL,
                PRIMARY KEY (job_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS data_jobs (
            job_id TEXT PRIMARY KEY,
            job_type TEXT NOT NULL,
            status TEXT NOT NULL,
            progress_json TEXT NOT NULL,
            state_json TEXT NOT NULL,
            result_json TEXT,
            payload_json TEXT NOT NULL,
            heartbeat_at TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS data_job_locks (
            job_type TEXT PRIMARY KEY,
            job_id TEXT NOT NULL,
            heartbeat_at TEXT NOT NULL
        )'
    );
    jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS data_jobs_type_status ON data_jobs (job_type, status)');
}

/** Före- och eftervärden för kontakter.conflicts. Idempotent. */
function jeab_ensure_contact_conflict_undo(PDO $pdo, string $driver): void
{
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS contact_conflict_undo (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                batch_id VARCHAR(32) NOT NULL,
                seq INT NOT NULL,
                kind VARCHAR(16) NOT NULL,
                entity VARCHAR(16) NOT NULL,
                entity_id VARCHAR(64) NOT NULL,
                before_json LONGTEXT NOT NULL,
                after_json LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL,
                undone TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY contact_conflict_undo_batch (batch_id, seq)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS contact_conflict_undo (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_id TEXT NOT NULL,
            seq INTEGER NOT NULL,
            kind TEXT NOT NULL,
            entity TEXT NOT NULL,
            entity_id TEXT NOT NULL,
            before_json TEXT NOT NULL,
            after_json TEXT NOT NULL,
            created_at TEXT NOT NULL,
            undone INTEGER NOT NULL DEFAULT 0
        )'
    );
    jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS contact_conflict_undo_batch ON contact_conflict_undo (batch_id, seq)');
}

/** Före-bild, länkändringar, skapade kontakter och fastighetsändringar. MySQL och sqlite. */
function jeab_ensure_contacts_sole_undo(PDO $pdo, string $driver): void
{
    if ($driver !== 'mysql' && $driver !== 'sqlite') {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS contacts_sole_undo (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                batch_id VARCHAR(32) NOT NULL,
                seq INT NOT NULL,
                kind VARCHAR(16) NOT NULL,
                entity VARCHAR(16) NOT NULL,
                entity_id VARCHAR(64) NOT NULL,
                before_json LONGTEXT NOT NULL,
                after_json LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL,
                undone TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY contacts_sole_undo_batch (batch_id, seq)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS contacts_sole_undo (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_id TEXT NOT NULL,
            seq INTEGER NOT NULL,
            kind TEXT NOT NULL,
            entity TEXT NOT NULL,
            entity_id TEXT NOT NULL,
            before_json TEXT NOT NULL,
            after_json TEXT NOT NULL,
            created_at TEXT NOT NULL,
            undone INTEGER NOT NULL DEFAULT 0
        )'
    );
    jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS contacts_sole_undo_batch ON contacts_sole_undo (batch_id, seq)');
}

/**
 * Ångring av omhämtad fastighetsinfo. Tabellerna skapas en gång.
 * Flaggan fastighet_undo_schema_v1 hoppar över DDL på senare anrop.
 * order_json är samma fält som ordern redan har, inklusive personnummer.
 */
function jeab_ensure_fastighet_undo(PDO $pdo, string $driver): void
{
    static $settled = null;
    $settled ??= new WeakMap();
    if (isset($settled[$pdo])) {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    $flag = 'fastighet_undo_schema_v1';
    if (jeab_settings_value($pdo, $driver, $flag) === 'ok') {
        $settled[$pdo] = true;

        return;
    }
    $GLOBALS['_JEAB_FASTIGHET_UNDO_DDL'] = (int) ($GLOBALS['_JEAB_FASTIGHET_UNDO_DDL'] ?? 0) + 1;
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS fastighet_undo (
                batch_id VARCHAR(32) NOT NULL,
                order_id INT NOT NULL,
                contact_id VARCHAR(64) NOT NULL DEFAULT \'\',
                order_json LONGTEXT NOT NULL,
                contact_json LONGTEXT NULL,
                undone TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (batch_id),
                KEY fastighet_undo_order (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS fastighet_refresh_log (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                event VARCHAR(16) NOT NULL,
                order_id INT NOT NULL,
                batch_id VARCHAR(32) NOT NULL,
                fields VARCHAR(255) NOT NULL,
                PRIMARY KEY (id),
                KEY fastighet_refresh_log_batch (batch_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS fastighet_undo (
                batch_id TEXT PRIMARY KEY,
                order_id INTEGER NOT NULL,
                contact_id TEXT NOT NULL DEFAULT \'\',
                order_json TEXT NOT NULL,
                contact_json TEXT,
                undone INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS fastighet_refresh_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL,
                event TEXT NOT NULL,
                order_id INTEGER NOT NULL,
                batch_id TEXT NOT NULL,
                fields TEXT NOT NULL
            )'
        );
        jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS fastighet_undo_order ON fastighet_undo (order_id)');
        jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS fastighet_refresh_log_batch ON fastighet_refresh_log (batch_id)');
    }
    if (!jeab_settings_put($pdo, $driver, $flag, 'ok')) {
        return;
    }
    $settled[$pdo] = true;
}

/** Ångerlogg för inbox.link_contacts. En rad per ändring. Idempotent. */
function jeab_ensure_inbox_link_undo_table(PDO $pdo, string $driver): void
{
    if ($driver !== 'mysql' && $driver !== 'sqlite') {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inbox_link_contacts_undo (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                undo_id VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL,
                thread_id VARCHAR(191) NOT NULL,
                tbl VARCHAR(64) NOT NULL,
                old_contact_id VARCHAR(191) NOT NULL DEFAULT \'\',
                new_contact_id VARCHAR(191) NOT NULL DEFAULT \'\',
                action VARCHAR(16) NOT NULL DEFAULT \'link\',
                old_value TEXT NULL,
                undone_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY inbox_link_contacts_undo_undo_id (undo_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        jeab_ensure_inbox_link_undo_columns($pdo, 'mysql');

        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inbox_link_contacts_undo (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            undo_id TEXT NOT NULL,
            created_at TEXT NOT NULL,
            thread_id TEXT NOT NULL,
            tbl TEXT NOT NULL,
            old_contact_id TEXT NOT NULL DEFAULT \'\',
            new_contact_id TEXT NOT NULL DEFAULT \'\',
            action TEXT NOT NULL DEFAULT \'link\',
            old_value TEXT,
            undone_at TEXT
        )'
    );
    jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS inbox_link_contacts_undo_undo_id ON inbox_link_contacts_undo (undo_id)');
    jeab_ensure_inbox_link_undo_columns($pdo, 'sqlite');
}

/**
 * Ångerlogg för contacts.merge_duplicates och contacts.normalize_addresses.
 * En rad per ändring. kind är link, contact_update, contact_delete eller address_normalize.
 * status är pending tills ändringen är gjord, sedan done. Idempotent.
 */
function jeab_ensure_contacts_merge_undo_table(PDO $pdo, string $driver): void
{
    if ($driver !== 'mysql' && $driver !== 'sqlite') {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS contacts_merge_undo (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                undo_id VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL,
                kind VARCHAR(32) NOT NULL,
                tbl VARCHAR(64) NOT NULL,
                row_key VARCHAR(191) NOT NULL DEFAULT \'\',
                col VARCHAR(64) NOT NULL DEFAULT \'\',
                old_value LONGTEXT NULL,
                new_value LONGTEXT NULL,
                status VARCHAR(16) NOT NULL DEFAULT \'pending\',
                undone_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY contacts_merge_undo_undo_id (undo_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $meta = $pdo->query("SHOW COLUMNS FROM contacts_merge_undo LIKE 'new_value'")->fetch(PDO::FETCH_ASSOC);
        $type = is_array($meta) ? strtolower((string) ($meta['Type'] ?? '')) : '';
        if ($type !== '' && !str_contains($type, 'longtext')) {
            $pdo->exec('ALTER TABLE contacts_merge_undo MODIFY new_value LONGTEXT NULL');
        }

        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS contacts_merge_undo (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            undo_id TEXT NOT NULL,
            created_at TEXT NOT NULL,
            kind TEXT NOT NULL,
            tbl TEXT NOT NULL,
            row_key TEXT NOT NULL DEFAULT \'\',
            col TEXT NOT NULL DEFAULT \'\',
            old_value TEXT,
            new_value TEXT,
            status TEXT NOT NULL DEFAULT \'pending\',
            undone_at TEXT
        )'
    );
    jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS contacts_merge_undo_undo_id ON contacts_merge_undo (undo_id)');
}

/**
 * action och old_value på befintliga inbox_link_contacts_undo. Idempotent.
 * action är link, create eller fill_name. old_value används av fill_name.
 */
function jeab_ensure_inbox_link_undo_columns(PDO $pdo, string $driver): void
{
    if ($driver !== 'mysql' && $driver !== 'sqlite') {
        return;
    }
    $wanted = $driver === 'mysql'
        ? [
            'action' => "VARCHAR(16) NOT NULL DEFAULT 'link'",
            'old_value' => 'TEXT NULL',
        ]
        : [
            'action' => "TEXT NOT NULL DEFAULT 'link'",
            'old_value' => 'TEXT',
        ];
    jeab_ensure_table_columns($pdo, 'inbox_link_contacts_undo', $wanted, $driver);
}

/** Ångerlogg för bookings.delete_one. Bokningen och kalenderloggens rader. Ingen JSON-fil. */
function jeab_ensure_booking_delete_undo(PDO $pdo, string $driver): void
{
    if ($driver !== 'mysql' && $driver !== 'sqlite') {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS booking_delete_undo (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                undo_id VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL,
                booking_id INT UNSIGNED NOT NULL,
                booking_json LONGTEXT NOT NULL,
                calendar_log_json LONGTEXT NULL,
                undone_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY booking_delete_undo_undo_id (undo_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS booking_delete_undo (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                undo_id TEXT NOT NULL,
                created_at TEXT NOT NULL,
                booking_id INTEGER NOT NULL,
                booking_json TEXT NOT NULL,
                calendar_log_json TEXT,
                undone_at TEXT
            )'
        );
        jeab_list_index_create($pdo, 'CREATE INDEX IF NOT EXISTS booking_delete_undo_undo_id ON booking_delete_undo (undo_id)');
    }
    jeab_ensure_table_columns($pdo, 'booking_delete_undo', [
        'calendar_log_json' => $driver === 'mysql' ? 'LONGTEXT NULL' : 'TEXT',
    ], $driver);
}

function jeab_ensure_json_doc_tables(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS purchases (
                reminder_id INT UNSIGNED NOT NULL,
                payload LONGTEXT NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (reminder_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS json_docs (
                doc_key VARCHAR(64) NOT NULL,
                payload LONGTEXT NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (doc_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS purchases (
            reminder_id INTEGER PRIMARY KEY,
            payload TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS json_docs (
            doc_key TEXT PRIMARY KEY,
            payload TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
}

function jeab_sql_store_pdo(): ?PDO
{
    if (jeab_driver() !== 'mysql') {
        return null;
    }
    try {
        $store = jeab_store();
        return $store instanceof JeabPdoStore ? $store->pdo() : null;
    } catch (Throwable) {
        return null;
    }
}

function jeab_sql_doc_cache_id(PDO $pdo, string $key): string
{
    return spl_object_id($pdo) . '|' . $key;
}

function jeab_sql_doc_get(PDO $pdo, string $key): ?array
{
    $ck = jeab_sql_doc_cache_id($pdo, $key);
    if (isset($GLOBALS['_JEAB_SQL_DOC']) && is_array($GLOBALS['_JEAB_SQL_DOC']) && array_key_exists($ck, $GLOBALS['_JEAB_SQL_DOC'])) {
        $hit = $GLOBALS['_JEAB_SQL_DOC'][$ck];

        return is_array($hit) ? $hit : null;
    }
    $stmt = $pdo->prepare('SELECT payload FROM json_docs WHERE doc_key = ?');
    $stmt->execute([$key]);
    $raw = $stmt->fetchColumn();
    $data = null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        $data = is_array($decoded) ? $decoded : null;
    }
    if (!isset($GLOBALS['_JEAB_SQL_DOC']) || !is_array($GLOBALS['_JEAB_SQL_DOC'])) {
        $GLOBALS['_JEAB_SQL_DOC'] = [];
    }
    $GLOBALS['_JEAB_SQL_DOC'][$ck] = $data;

    return $data;
}

function jeab_sql_doc_put(PDO $pdo, string $key, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }
    $at = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    if (jeab_driver() === 'mysql') {
        $sql = 'INSERT INTO json_docs (doc_key, payload, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = VALUES(updated_at)';
    } else {
        $sql = 'INSERT INTO json_docs (doc_key, payload, updated_at) VALUES (?, ?, ?) ON CONFLICT(doc_key) DO UPDATE SET payload = excluded.payload, updated_at = excluded.updated_at';
    }
    $pdo->prepare($sql)->execute([$key, $json, $at]);
    if (!isset($GLOBALS['_JEAB_SQL_DOC']) || !is_array($GLOBALS['_JEAB_SQL_DOC'])) {
        $GLOBALS['_JEAB_SQL_DOC'] = [];
    }
    $GLOBALS['_JEAB_SQL_DOC'][jeab_sql_doc_cache_id($pdo, $key)] = $data;
}

function jeab_sql_purchases_decode(mixed $reminderId, mixed $payload): ?array
{
    $data = json_decode((string) $payload, true);
    if (!is_array($data)) {
        return null;
    }
    $id = (int) ($reminderId ?? $data['reminder_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $data['reminder_id'] = $id;
    return $data;
}

function jeab_sql_purchases_is_mysql(PDO $pdo): bool
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
}

function jeab_sql_purchases_load(PDO $pdo): array
{
    $GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] = (int) ($GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] ?? 0) + 1;
    $out = [];
    foreach ($pdo->query('SELECT reminder_id, payload FROM purchases') as $row) {
        $data = jeab_sql_purchases_decode($row['reminder_id'] ?? 0, $row['payload'] ?? '');
        if (is_array($data)) {
            $out[(string) $data['reminder_id']] = $data;
        }
    }
    return $out;
}

/**
 * Kolumnerna listan filtrerar på. company/klart skrivs vid varje sparning
 * så Ordrar inte behöver läsa hela payload-tabellen.
 *
 * @param array<string, mixed> $row
 * @return array{company:string,kind:?string,seller:?string,klart:int,created_at:?string}
 */
function jeab_sql_purchases_index_values(array $row): array
{
    $company = 'jarfalla';
    if (function_exists('jeab_normalize_company')) {
        $company = jeab_normalize_company($row['company'] ?? null) ?? 'jarfalla';
    } elseif (is_string($row['company'] ?? null) && trim((string) $row['company']) !== '') {
        $company = strtolower(trim((string) $row['company']));
    }
    $cut = static function (string $s): string {
        return function_exists('mb_substr') ? mb_substr($s, 0, 64) : substr($s, 0, 64);
    };
    $kind = trim((string) ($row['kind'] ?? ''));
    $seller = trim((string) ($row['seller'] ?? $row['person'] ?? ''));
    $klart = 0;
    if (function_exists('jeab_ordrar_allt_klart')) {
        $klart = jeab_ordrar_allt_klart($row) ? 1 : 0;
    } elseif (!empty($row['allt_klart']) || trim((string) ($row['closed_at'] ?? '')) !== '') {
        $klart = 1;
    }
    $created = trim((string) ($row['created_at'] ?? ''));

    return [
        'company' => $cut($company),
        'kind' => $kind === '' ? null : $cut($kind),
        'seller' => $seller === '' ? null : $cut($seller),
        'klart' => $klart,
        'created_at' => $created === '' ? null : $created,
    ];
}

/**
 * purchases utan list_json (och ibland utan company). Sidan läser payload
 * och filtrerar i PHP. Tick lägger till kolumnen. Ingen ALTER här.
 *
 * @return list<array<string, mixed>>
 */
function jeab_sql_purchases_list_from_payload(PDO $pdo, string $company, bool $klart, ?int $limit, int $offset, ?string $kind): array
{
    try {
        $stmt = $pdo->query('SELECT reminder_id, updated_at, payload FROM purchases');
        $rows = $stmt ? $stmt->fetchAll() : [];
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['reminder_id'] ?? 0);
        $data = jeab_sql_purchases_decode($id, $row['payload'] ?? '');
        if (!is_array($data) || !empty($data['_jeab_list_skip'])) {
            continue;
        }
        $rowCompany = function_exists('jeab_normalize_company')
            ? (jeab_normalize_company($data['company'] ?? null) ?? 'jarfalla')
            : 'jarfalla';
        if ($rowCompany !== $company) {
            continue;
        }
        $done = function_exists('jeab_ordrar_allt_klart') && jeab_ordrar_allt_klart($data);
        if ($done !== $klart) {
            continue;
        }
        if ($kind !== null && $kind !== '') {
            $rowKind = function_exists('jeab_normalize_job_key')
                ? jeab_normalize_job_key((string) ($data['kind'] ?? ''))
                : (string) ($data['kind'] ?? '');
            if ($rowKind !== null && $rowKind !== '' && $rowKind !== $kind) {
                continue;
            }
        }
        $at = trim((string) ($row['updated_at'] ?? ''));
        if ($at !== '' && trim((string) ($data['updated_at'] ?? '')) === '') {
            $data['updated_at'] = $at;
        }
        $out[] = $data;
    }
    if ($offset > 0 || ($limit !== null && $limit > 0)) {
        $out = array_slice($out, max(0, $offset), $limit !== null && $limit > 0 ? $limit : null);
    }

    return $out;
}

/**
 * Bolag och Allt klart i SQL. Sidan läser bara flaggorna. Tick fyller
 * company och list_json. Innan indexflaggan är ok ligger predikatet kvar med
 * «OR company IS NULL», så en ofylld rad inte försvinner.
 *
 * Tavlan läser list_json så fort någon rad i urvalet har den, även innan
 * purchases_list_json_v1 är ok. Tom list_json faller tillbaka till payload
 * för just den raden, i en följdfråga. Finns ingen list_json alls läses
 * payload som tidigare, i ett svep. Öppnad order läser alltid payload.
 *
 * $limit och $offset skär sidan i SQL. Anroparen får bara använda dem när
 * ordningen är created_at (Allt klart utan person-, produkt-, chip- eller
 * sökfilter). Öppna ordrar sorteras på installationsdag i PHP och begränsas
 * inte här.
 *
 * @return list<array<string, mixed>>
 */
function jeab_sql_purchases_list(PDO $pdo, string $company, bool $klart, ?int $limit = null, int $offset = 0, ?string $kind = null): array
{
    $GLOBALS['_JEAB_PURCHASE_LIST_SQL'] = (int) ($GLOBALS['_JEAB_PURCHASE_LIST_SQL'] ?? 0) + 1;
    if (function_exists('jeab_purchases_store_ensure')) {
        jeab_purchases_store_ensure($pdo);
    }
    $driver = jeab_sql_purchases_is_mysql($pdo) ? 'mysql' : 'sqlite';
    $strict = false;
    $flagOk = false;
    if (function_exists('jeab_settings_value')) {
        $strict = jeab_settings_value($pdo, $driver, 'purchases_list_index_v1') === 'ok';
        $flagOk = jeab_settings_value($pdo, $driver, 'purchases_list_json_v1') === 'ok';
    }
    $flag = $klart ? 1 : 0;
    $kind = $kind !== null && function_exists('jeab_normalize_job_key')
        ? jeab_normalize_job_key($kind)
        : ($kind !== null && $kind !== '' ? $kind : null);
    if ($strict) {
        $where = 'company = ? AND klart = ?';
        $args = [$company, $flag];
        // Produktfiltret sitter i SQL så Sol inte avkodar batteri och laddbox.
        // Rader utan kind följer med och silas i PHP.
        if ($kind !== null && $kind !== '') {
            $where .= " AND (kind = ? OR kind IS NULL OR kind = '')";
            $args[] = $kind;
        }
    } else {
        $where = "(klart = ? OR company IS NULL OR company = '')"
            . " AND (company = ? OR company IS NULL OR company = '')";
        $args = [$flag, $company];
    }
    $useList = false;
    if (empty($GLOBALS['_JEAB_PURCHASE_LIST_FORCE_PAYLOAD'])) {
        if ($flagOk) {
            $useList = true;
        } else {
            try {
                $probe = $pdo->prepare(
                    'SELECT 1 FROM purchases WHERE ' . $where
                    . " AND list_json IS NOT NULL AND list_json != '' LIMIT 1"
                );
                $probe->execute($args);
                $useList = (bool) $probe->fetchColumn();
            } catch (Throwable) {
                // Kolumnen finns inte än. Tick lägger till den. Sidan läser payload.
                return jeab_sql_purchases_list_from_payload($pdo, $company, $klart, $limit, $offset, $kind);
            }
        }
    }
    $GLOBALS['_JEAB_PURCHASE_LIST_SQL_MODE'] = ($useList ? 'list' : 'payload') . ($strict ? '-strict' : '-legacy');
    $cols = $useList ? 'reminder_id, updated_at, list_json' : 'reminder_id, updated_at, payload';
    $sql = 'SELECT ' . $cols . ' FROM purchases WHERE ' . $where
        . ' ORDER BY created_at DESC, reminder_id DESC';
    if ($limit !== null && $limit > 0) {
        $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . max(0, $offset);
    }
    $log = $GLOBALS['_JEAB_PURCHASE_LIST_SQL_LOG'] ?? [];
    if (!is_array($log)) {
        $log = [];
    }
    $log[] = $sql;
    $GLOBALS['_JEAB_PURCHASE_LIST_SQL_LOG'] = array_slice($log, -12);
    $GLOBALS['_JEAB_PURCHASE_LIST_SQL_LAST'] = $sql;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $seq = [];
    $need = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['reminder_id'] ?? 0);
        $at = trim((string) ($row['updated_at'] ?? ''));
        if ($useList && trim((string) ($row['list_json'] ?? '')) === '') {
            $seq[] = ['id' => $id, 'at' => $at, 'data' => null];
            if ($id > 0) {
                $need[$id] = $id;
            }
            continue;
        }
        $decodeStarted = microtime(true);
        if ($useList) {
            $data = jeab_sql_purchases_decode($id, $row['list_json'] ?? '');
        } else {
            $GLOBALS['_JEAB_PURCHASE_LIST_PAYLOAD'] = (int) ($GLOBALS['_JEAB_PURCHASE_LIST_PAYLOAD'] ?? 0) + 1;
            $data = jeab_sql_purchases_decode($id, $row['payload'] ?? '');
        }
        if (function_exists('jeab_timing_add')) {
            jeab_timing_add($useList ? 'list_json' : 'payload_json', microtime(true) - $decodeStarted);
        }
        $seq[] = ['id' => $id, 'at' => $at, 'data' => $data];
    }
    if ($need !== []) {
        $ids = array_values($need);
        $GLOBALS['_JEAB_PURCHASE_LIST_PAYLOAD'] = (int) ($GLOBALS['_JEAB_PURCHASE_LIST_PAYLOAD'] ?? 0) + count($ids);
        $place = implode(',', array_fill(0, count($ids), '?'));
        $fillSql = 'SELECT reminder_id, payload FROM purchases WHERE reminder_id IN (' . $place . ')';
        $fillLog = $GLOBALS['_JEAB_PURCHASE_LIST_SQL_LOG'] ?? [];
        if (!is_array($fillLog)) {
            $fillLog = [];
        }
        $fillLog[] = $fillSql;
        $GLOBALS['_JEAB_PURCHASE_LIST_SQL_LOG'] = array_slice($fillLog, -12);
        $fill = $pdo->prepare($fillSql);
        $fill->execute($ids);
        $byId = [];
        foreach ($fill->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rid = (int) ($row['reminder_id'] ?? 0);
            $byId[$rid] = jeab_sql_purchases_decode($rid, $row['payload'] ?? '');
        }
        foreach ($seq as $i => $slot) {
            if ($slot['data'] === null) {
                $seq[$i]['data'] = $byId[(int) $slot['id']] ?? null;
            }
        }
    }
    $out = [];
    foreach ($seq as $slot) {
        $data = $slot['data'];
        // Tick märker oläsbar payload så den lämnar kön. Den är ingen order.
        if (!is_array($data) || !empty($data['_jeab_list_skip'])) {
            continue;
        }
        $at = (string) $slot['at'];
        if ($at !== '' && trim((string) ($data['updated_at'] ?? '')) === '') {
            $data['updated_at'] = $at;
        }
        $out[] = $data;
    }

    return $out;
}

function jeab_sql_purchases_get(PDO $pdo, int $reminderId): ?array
{
    if ($reminderId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT reminder_id, payload FROM purchases WHERE reminder_id = ?');
    $stmt->execute([$reminderId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    return jeab_sql_purchases_decode($row['reminder_id'] ?? 0, $row['payload'] ?? '');
}

/** Samma predikat som listan. Används för Allt klart-sidans antal. */
function jeab_sql_purchases_list_count(PDO $pdo, string $company, bool $klart): int
{
    if (function_exists('jeab_purchases_store_ensure')) {
        jeab_purchases_store_ensure($pdo);
    }
    $driver = jeab_sql_purchases_is_mysql($pdo) ? 'mysql' : 'sqlite';
    $strict = function_exists('jeab_settings_value')
        && jeab_settings_value($pdo, $driver, 'purchases_list_index_v1') === 'ok';
    $flag = $klart ? 1 : 0;
    if ($strict) {
        $sql = 'SELECT COUNT(*) FROM purchases WHERE company = ? AND klart = ?';
        $args = [$company, $flag];
    } else {
        $sql = 'SELECT COUNT(*) FROM purchases WHERE (klart = ? OR company IS NULL OR company = \'\')'
            . ' AND (company = ? OR company IS NULL OR company = \'\')';
        $args = [$flag, $company];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);

    return (int) $stmt->fetchColumn();
}

/**
 * Tick-markör i urvalet. Då kan LIMIT tappa en riktig rad, så sidan läser
 * hela list_json-urvalet i stället.
 */
function jeab_sql_purchases_has_list_skip(PDO $pdo, string $company, bool $klart): bool
{
    $flag = $klart ? 1 : 0;
    $stmt = $pdo->prepare(
        'SELECT 1 FROM purchases WHERE company = ? AND klart = ?'
        . ' AND instr(list_json, \'"_jeab_list_skip"\') > 0 LIMIT 1'
    );
    $stmt->execute([$company, $flag]);

    return (bool) $stmt->fetchColumn();
}

function jeab_sql_purchases_contact_column(array $row): string
{
    $id = trim((string) ($row['contact_id'] ?? ''));

    return mb_substr($id, 0, 64);
}

/**
 * Lägger bara inkommande fält ovanpå den låsta raden.
 * Fält som anropet inte skickar med lämnas kvar.
 *
 * @param array<string, mixed> $current
 * @param array<string, mixed> $incoming
 * @return array<string, mixed>
 */
function jeab_sql_purchases_merge_fields(array $current, array $incoming): array
{
    $merged = $current;
    foreach ($incoming as $key => $value) {
        if ($key === 'reminder_id') {
            continue;
        }
        if ($key === 'snapshots' && is_array($value)) {
            $prior = is_array($current['snapshots'] ?? null) ? $current['snapshots'] : [];
            $bag = function_exists('jeab_snapshots_overlay_types')
                ? jeab_snapshots_overlay_types($prior, $value)
                : $value;
            if ($bag === []) {
                unset($merged['snapshots']);
            } else {
                $merged['snapshots'] = $bag;
            }
            continue;
        }
        if (!array_key_exists($key, $current) || $current[$key] !== $value) {
            $merged[$key] = $value;
        }
    }
    $merged['reminder_id'] = (int) ($incoming['reminder_id'] ?? $current['reminder_id'] ?? 0);

    return $merged;
}

/** @return array<string, mixed>|null */
function jeab_sql_purchases_lock_row(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    if (!jeab_sql_purchases_is_mysql($pdo)) {
        $pdo->prepare('UPDATE purchases SET contact_indexed = contact_indexed WHERE reminder_id = ?')->execute([$id]);
    }
    $sql = jeab_sql_purchases_is_mysql($pdo)
        ? 'SELECT reminder_id, payload FROM purchases WHERE reminder_id = ? FOR UPDATE'
        : 'SELECT reminder_id, payload FROM purchases WHERE reminder_id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $locked = $stmt->fetch();
    if (!is_array($locked)) {
        return null;
    }

    return jeab_sql_purchases_decode($locked['reminder_id'] ?? 0, $locked['payload'] ?? '');
}

/**
 * Tavlan. Tunga fält stannar i payload. Samma urval som jeab_ordrar_board_record
 * när den finns, så #921 kan läsa list_json utan att sparningen saknar kolumnen.
 *
 * @param array<string, mixed> $row
 */
function jeab_sql_purchases_list_json(array $row): string
{
    if (function_exists('jeab_ordrar_board_record')) {
        $source = jeab_ordrar_board_record($row);
    } else {
        $drop = [
            'install_note' => true,
            'install_note_rev' => true,
            'note' => true,
            'notes' => true,
            'lookup' => true,
            'lookup_raw' => true,
            'raw' => true,
            'html' => true,
            'body' => true,
            'message' => true,
            'pdf' => true,
            'base64' => true,
        ];
        $source = [];
        foreach ($row as $key => $value) {
            $name = (string) $key;
            if (isset($drop[$name]) || str_ends_with($name, '_raw') || str_ends_with($name, '_html') || str_ends_with($name, '_pdf') || str_ends_with($name, '_body')) {
                continue;
            }
            $source[$key] = $value;
        }
        $source['reminder_id'] = (int) ($row['reminder_id'] ?? 0);
    }
    $json = json_encode($source, JSON_UNESCAPED_UNICODE);

    return is_string($json) ? $json : '{}';
}

/** @param array<string, mixed> $row */
function jeab_sql_purchases_store_row(PDO $pdo, array $row, bool $exists): void
{
    $id = (int) ($row['reminder_id'] ?? 0);
    $json = json_encode($row, JSON_UNESCAPED_UNICODE);
    if ($json === false || $id <= 0) {
        throw new RuntimeException('Ordern kunde inte sparas.');
    }
    $at = trim((string) ($row['updated_at'] ?? ''));
    if ($at === '') {
        $at = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    }
    $contactId = jeab_sql_purchases_contact_column($row);
    $idx = jeab_sql_purchases_index_values($row);
    $listJson = jeab_sql_purchases_list_json($row);
    if ($exists) {
        $pdo->prepare(
            'UPDATE purchases SET payload = ?, updated_at = ?, contact_id = ?, contact_indexed = 1, company = ?, kind = ?, seller = ?, klart = ?, created_at = ?, list_json = ? WHERE reminder_id = ?'
        )->execute([
            $json,
            $at,
            $contactId,
            $idx['company'],
            $idx['kind'],
            $idx['seller'],
            $idx['klart'],
            $idx['created_at'],
            $listJson,
            $id,
        ]);

        return;
    }
    if (jeab_sql_purchases_is_mysql($pdo)) {
        $sql = 'INSERT INTO purchases (reminder_id, payload, updated_at, contact_id, contact_indexed, company, kind, seller, klart, created_at, list_json) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = VALUES(updated_at), contact_id = VALUES(contact_id), contact_indexed = 1, company = VALUES(company), kind = VALUES(kind), seller = VALUES(seller), klart = VALUES(klart), created_at = VALUES(created_at), list_json = VALUES(list_json)';
    } else {
        $sql = 'INSERT INTO purchases (reminder_id, payload, updated_at, contact_id, contact_indexed, company, kind, seller, klart, created_at, list_json) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?) ON CONFLICT(reminder_id) DO UPDATE SET payload = excluded.payload, updated_at = excluded.updated_at, contact_id = excluded.contact_id, contact_indexed = 1, company = excluded.company, kind = excluded.kind, seller = excluded.seller, klart = excluded.klart, created_at = excluded.created_at, list_json = excluded.list_json';
    }
    $pdo->prepare($sql)->execute([
        $id,
        $json,
        $at,
        $contactId,
        $idx['company'],
        $idx['kind'],
        $idx['seller'],
        $idx['klart'],
        $idx['created_at'],
        $listJson,
    ]);
}

/**
 * Skriver en order i en transaktion. Raden låses med SELECT … FOR UPDATE
 * (SQLite tar skrivlåset först). Bara fält som skiljer sig läggs på den
 * låsta raden, så ett annat fält från Fortnox, webbshop eller UI blir kvar.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_sql_purchases_put(PDO $pdo, array $row): array
{
    $id = (int) ($row['reminder_id'] ?? 0);
    if ($id <= 0) {
        return $row;
    }
    if (!function_exists('jeab_purchases_store_ensure')) {
        require_once __DIR__ . '/purchase_store.php';
    }
    if (!function_exists('jeab_pdo_tx_busy') || !jeab_pdo_tx_busy($pdo)) {
        jeab_purchases_store_ensure($pdo);
    }
    if (!jeab_sql_purchases_is_mysql($pdo)) {
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }
    if (!function_exists('jeab_idnr_normalize_row')) {
        require_once __DIR__ . '/idnr.php';
    }
    $row = jeab_idnr_normalize_row($row);
    $row['reminder_id'] = $id;

    $saved = jeab_pdo_transaction($pdo, static function (PDO $pdo) use ($row, $id): array {
        $current = jeab_sql_purchases_lock_row($pdo, $id);
        $merged = jeab_sql_purchases_merge_fields(is_array($current) ? $current : [], $row);
        $merged['reminder_id'] = $id;
        $merged = jeab_idnr_normalize_row($merged);
        if (function_exists('jeab_contacts_sole_order_strip_customer')) {
            $merged = jeab_contacts_sole_order_strip_customer($merged);
        }
        jeab_sql_purchases_store_row($pdo, $merged, is_array($current));

        return $merged;
    });
    jeab_ordrar_board_cache_drop();

    return $saved;
}

/** Tavlan cachar formade rader. Ett sparande gör cachen ogiltig. */
function jeab_ordrar_board_cache_drop(): void
{
    $GLOBALS['_JEAB_ORDRAR_VISIBLE'] = [];
    foreach (glob(sys_get_temp_dir() . '/jeab-ordrar-vis-*.ser') ?: [] as $file) {
        if (is_string($file) && is_file($file)) {
            @unlink($file);
        }
    }
}

/**
 * Läser en rad med radlås, ändrar den och skriver tillbaka samma reminder_id.
 * Saknad rad skapas inte. Mutator som returnerar null lämnar raden orörd.
 *
 * @param callable(array<string, mixed>): ?array $mutator
 * @return array<string, mixed>|null
 */
function jeab_sql_purchases_patch(PDO $pdo, int $reminderId, callable $mutator): ?array
{
    if ($reminderId <= 0) {
        return null;
    }
    if (!function_exists('jeab_purchases_store_ensure')) {
        require_once __DIR__ . '/purchase_store.php';
    }
    jeab_purchases_store_ensure($pdo);

    return jeab_pdo_transaction($pdo, static function (PDO $pdo) use ($reminderId, $mutator): ?array {
        $sql = jeab_sql_purchases_is_mysql($pdo)
            ? 'SELECT reminder_id, payload FROM purchases WHERE reminder_id = ? FOR UPDATE'
            : 'SELECT reminder_id, payload FROM purchases WHERE reminder_id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$reminderId]);
        $locked = $stmt->fetch();
        $current = is_array($locked)
            ? jeab_sql_purchases_decode($locked['reminder_id'] ?? 0, $locked['payload'] ?? '')
            : null;
        if (!is_array($current)) {
            return null;
        }
        $next = $mutator($current);
        if (!is_array($next)) {
            return $current;
        }
        if (isset($next['snapshots']) && is_array($next['snapshots']) && function_exists('jeab_snapshots_overlay_types')) {
            $bag = jeab_snapshots_overlay_types([], $next['snapshots']);
            if ($bag === []) {
                unset($next['snapshots']);
            } else {
                $next['snapshots'] = $bag;
            }
        }
        if (!function_exists('jeab_idnr_normalize_row')) {
            require_once __DIR__ . '/idnr.php';
        }
        $next = jeab_idnr_normalize_row($next);
        $next['reminder_id'] = $reminderId;
        if (function_exists('jeab_contacts_sole_order_strip_customer')) {
            $next = jeab_contacts_sole_order_strip_customer($next);
        }
        $at = trim((string) ($next['updated_at'] ?? ''));
        if ($at === '') {
            $at = trim((string) ($current['updated_at'] ?? ''));
        }
        if ($at === '') {
            $at = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        }
        $next['updated_at'] = $at;
        jeab_sql_purchases_store_row($pdo, $next, true);

        return $next;
    });
}

function jeab_sql_purchases_delete(PDO $pdo, int $reminderId): void
{
    if ($reminderId <= 0) {
        return;
    }
    if (!function_exists('jeab_purchase_delete_guard')) {
        throw new RuntimeException('Ordern kan bara raderas via Radera.');
    }
    jeab_purchase_delete_guard();
    $pdo->prepare('DELETE FROM purchases WHERE reminder_id = ?')->execute([$reminderId]);
}

function jeab_mysql_import_json_leftovers(PDO $pdo, bool $explicit = false): void
{
    if (!$explicit) {
        return;
    }
    if (jeab_driver() !== 'mysql') {
        return;
    }
    try {
        $done = $pdo->query("SELECT value FROM settings WHERE `key` = 'json_import_done'")->fetchColumn();
        if ($done === '1') {
            return;
        }
    } catch (Throwable) {
        return;
    }
    try {
        jeab_mysql_import_store_json($pdo);
        jeab_mysql_import_purchases_json($pdo);
        jeab_mysql_import_calendar_log_json($pdo);
        if (jeab_driver() === 'mysql') {
            $sql = 'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)';
        } else {
            $sql = 'INSERT INTO settings ("key", value) VALUES (?, ?) ON CONFLICT("key") DO UPDATE SET value = excluded.value';
        }
        $pdo->prepare($sql)->execute(['json_import_done', '1']);
        if (function_exists('jeab_settings_cache_store')) {
            jeab_settings_cache_store($pdo, 'mysql', 'json_import_done', '1');
        }
    } catch (Throwable) {
        // Import får inte ta ner sajten.
    }
}

function jeab_mysql_import_store_json(PDO $pdo): void
{
    $path = function_exists('jeab_json_path') ? jeab_json_path() : '';
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return;
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return;
    }
    $haveCustomers = [];
    foreach ($pdo->query('SELECT id, name FROM customers') as $row) {
        $haveCustomers[(int) $row['id']] = true;
        $haveCustomers['name:' . (string) $row['name']] = (int) $row['id'];
    }
    foreach ($data['customers'] ?? [] as $customer) {
        if (!is_array($customer)) {
            continue;
        }
        $id = (int) ($customer['id'] ?? 0);
        $name = trim((string) ($customer['name'] ?? ''));
        $created = trim((string) ($customer['created_at'] ?? ''));
        if ($id <= 0 || $name === '') {
            continue;
        }
        if (!empty($haveCustomers[$id]) || isset($haveCustomers['name:' . $name])) {
            continue;
        }
        $pdo->prepare('INSERT INTO customers (id, name, created_at) VALUES (?, ?, ?)')
            ->execute([$id, $name, $created !== '' ? $created : date('Y-m-d H:i:s')]);
        $haveCustomers[$id] = true;
        $haveCustomers['name:' . $name] = $id;
    }
    $haveBookings = [];
    foreach ($pdo->query('SELECT id FROM bookings') as $row) {
        $haveBookings[(int) $row['id']] = true;
    }
    $maxId = 0;
    foreach ($data['bookings'] ?? [] as $booking) {
        if (!is_array($booking)) {
            continue;
        }
        $id = (int) ($booking['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $contactId = trim((string) ($booking['contact_id'] ?? ''));
        $contactId = $contactId !== '' ? $contactId : null;
        if (!empty($haveBookings[$id])) {
            if ($contactId !== null) {
                $pdo->prepare('UPDATE bookings SET contact_id = ? WHERE id = ? AND (contact_id IS NULL OR contact_id = \'\')')->execute([$contactId, $id]);
            }
            continue;
        }
        $names = function_exists('jeab_parse_assignees')
            ? jeab_parse_assignees($booking['assignees'] ?? $booking['assignee'] ?? [])
            : [];
        $assignee = $names !== []
            ? (function_exists('jeab_assignees_join') ? jeab_assignees_join($names) : implode(', ', $names))
            : trim((string) ($booking['assignee'] ?? ''));
        $assignees = function_exists('jeab_assignees_json') ? jeab_assignees_json($names) : json_encode($names);
        $importValues = [
            $id,
            (int) ($booking['customer_id'] ?? 0) ?: 1,
            (string) ($booking['customer_name'] ?? ''),
            $booking['email'] ?? null,
            $booking['phone'] ?? null,
            $booking['address'] ?? null,
            $booking['notes'] ?? null,
            $assignee,
            $assignees,
            function_exists('jeab_normalize_company')
                ? (jeab_normalize_company($booking['company'] ?? null) ?? 'jarfalla')
                : 'jarfalla',
            $booking['job_type'] ?? null,
            (string) ($booking['starts_at'] ?? ''),
            (string) ($booking['ends_at'] ?? ''),
            (string) ($booking['status'] ?? 'booked'),
            $contactId,
            (string) ($booking['created_at'] ?? date('Y-m-d H:i:s')),
            (string) ($booking['updated_at'] ?? date('Y-m-d H:i:s')),
        ];
        $importCols = 'id, customer_id, customer_name, email, phone, address, notes, assignee, assignees, company, job_type, starts_at, ends_at, status, contact_id, created_at, updated_at';
        $importMarks = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?';
        if (jeab_booking_ends_sort_column($pdo)) {
            $importCols .= ', ends_sort';
            $importMarks .= ', ?';
            $importValues[] = jeab_booking_ends_sort_value((string) ($booking['ends_at'] ?? ''), (string) ($booking['starts_at'] ?? ''));
        }
        [$importList, $importValues] = jeab_copy_filter_write($pdo, 'bookings', array_map('trim', explode(',', $importCols)), $importValues);
        $pdo->prepare('INSERT INTO bookings (' . implode(', ', $importList) . ') VALUES (' . implode(', ', array_fill(0, count($importList), '?')) . ')')->execute($importValues);
        $haveBookings[$id] = true;
        if ($id > $maxId) {
            $maxId = $id;
        }
    }
    if ($maxId > 0 && jeab_driver() === 'mysql') {
        $pdo->exec('ALTER TABLE bookings AUTO_INCREMENT = ' . ($maxId + 1));
    }
}

function jeab_mysql_import_purchases_json(PDO $pdo): void
{
    unset($pdo);
}

function jeab_mysql_import_calendar_log_json(PDO $pdo): void
{
    if (jeab_sql_doc_get($pdo, 'calendar_log') !== null) {
        return;
    }
    $path = function_exists('jeab_calendar_log_path') ? jeab_calendar_log_path() : '';
    if ($path === '' || !empty($GLOBALS['_JEAB_CALENDAR_LOG_PATH']) || !is_file($path)) {
        return;
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return;
    }
    jeab_sql_doc_put($pdo, 'calendar_log', $data);
}
