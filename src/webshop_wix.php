<?php

declare(strict_types=1);

/**
 * Wix → flowstack.example.test utan bot: «Order paid»-automation POST:ar till webshop-hook.php med
 * en egen webhook-nyckel. Endpointen läser bara order-id + sajt ur anropet och hämtar
 * hela ordern från Wix API på servern. Orderinnehåll i anropet används aldrig.
 *
 * Hemligheter (aldrig git/loggar/backup):
 *   data/.private/secrets/webshop-webhook.json  {"key": "..."}            (kan bara trigga hämtning)
 *   data/.private/secrets/wix-api.json          {"api_key": "...", "account_id": "..."}
 */

function jeab_webshop_secret_path(string $name): string
{
    return jeab_data_dir() . '/secrets/' . $name;
}

function jeab_webshop_secret_read(string $name): array
{
    $p = jeab_webshop_secret_path($name);
    $d = is_file($p) ? json_decode((string) file_get_contents($p), true) : null;
    return is_array($d) ? $d : [];
}

function jeab_webshop_secret_write(string $name, array $data): void
{
    $p = jeab_webshop_secret_path($name);
    if (!is_dir(dirname($p))) {
        @mkdir(dirname($p), 0700, true);
    }
    $tmp = $p . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($tmp, 0600);
    @rename($tmp, $p);
}

function jeab_webshop_webhook_key(): string
{
    return trim((string) (jeab_webshop_secret_read('webshop-webhook.json')['key'] ?? ''));
}

function jeab_webshop_webhook_key_ensure(): string
{
    $k = jeab_webshop_webhook_key();
    if ($k === '') {
        $k = bin2hex(random_bytes(24));
        jeab_webshop_secret_write('webshop-webhook.json', ['key' => $k, 'created_at' => jeab_now()]);
    }
    return $k;
}

/** Nyckeln får komma som header X-Webshop-Key, Bearer eller body.key. Bara POST. Inte query. */
function jeab_webshop_webhook_key_ok(array $input): bool
{
    $want = jeab_webshop_webhook_key();
    if ($want === '' || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        return false;
    }
    $cands = [
        (string) ($_SERVER['HTTP_X_WEBSHOP_KEY'] ?? ''),
        is_string($input['key'] ?? null) ? $input['key'] : '',
    ];
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        $cands[] = $m[1];
    }
    foreach ($cands as $c) {
        if (trim($c) !== '' && hash_equals($want, trim($c))) {
            return true;
        }
    }
    return false;
}

/**
 * 202 till Wix innan hämtning. PHP-FPM släpper klienten; CGI skickar
 * Connection: close. Missad hämtning tas ikapp av dagsjobbet.
 */
function jeab_webshop_hook_accepted(): void
{
    http_response_code(202);
    $body = json_encode(['ok' => true]);
    @ignore_user_abort(true);
    if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg' && !headers_sent()) {
        header('Content-Length: ' . strlen($body));
        header('Connection: close');
    }
    echo $body;
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();

        return;
    }
    if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }
}

/** Plockar bara order-id och sajt ur anropet. */
function jeab_webshop_event_ids(array $input): array
{
    $id = '';
    foreach ([
        $input['orderId'] ?? null, $input['order_id'] ?? null, $input['data']['orderId'] ?? null,
        $input['data']['order']['id'] ?? null, $input['order']['id'] ?? null, $input['entityId'] ?? null,
        $input['data']['id'] ?? null, $input['id'] ?? null,
    ] as $c) {
        if (is_string($c) && preg_match('/^[0-9a-f-]{36}$/i', trim($c))) {
            $id = strtolower(trim($c));
            break;
        }
    }
    $site = '';
    foreach ([$_GET['site'] ?? null, $input['siteId'] ?? null, $input['site_id'] ?? null, $input['metaSiteId'] ?? null, $input['data']['siteId'] ?? null] as $c) {
        $c = is_string($c) ? trim($c) : '';
        if ($c === '') {
            continue;
        }
        $co = jeab_normalize_company($c);
        if ($co !== null) {
            $c = (string) array_search($co, JEAB_WEBSHOP_SITES, true);
        }
        if (isset(JEAB_WEBSHOP_SITES[$c])) {
            $site = $c;
            break;
        }
    }
    return ['order_id' => $id, 'site_id' => $site];
}

function jeab_webshop_catchup_deadline(): ?float
{
    $given = $GLOBALS['_JEAB_WIX_CATCHUP_DEADLINE'] ?? null;
    if (is_int($given) || is_float($given)) {
        return (float) $given;
    }

    return null;
}

function jeab_webshop_catchup_time_left(): ?float
{
    $deadline = jeab_webshop_catchup_deadline();
    if ($deadline === null) {
        return null;
    }

    return $deadline - microtime(true);
}

/**
 * Tidsgränsen tog slut. Dygnets stämpel får inte sättas, annars räknas
 * ett avbrutet ikapp som klart och nästa tick hoppar över resten.
 *
 * @param array<string, mixed> $res
 */
function jeab_webshop_catchup_stopped_for_time(array $res): bool
{
    if (empty($res['ok'])) {
        return true;
    }
    foreach ($res['sites'] ?? [] as $site) {
        if (is_array($site) && (string) ($site['error'] ?? '') === 'tid') {
            return true;
        }
    }

    return false;
}

function jeab_webshop_wix_request(string $siteId, string $method, string $path, ?array $body = null): array
{
    $left = jeab_webshop_catchup_time_left();
    if ($left !== null && $left < 0.2) {
        throw new RuntimeException('tid');
    }
    if (isset($GLOBALS['_JEAB_WIX_REQUEST']) && is_callable($GLOBALS['_JEAB_WIX_REQUEST'])) {
        return ($GLOBALS['_JEAB_WIX_REQUEST'])($siteId, $method, $path, $body);
    }
    if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('wix', $method . ' ' . $path)) {
        return ['demo' => true];
    }
    $sec = jeab_webshop_secret_read('wix-api.json');
    $key = trim((string) ($sec['api_key'] ?? ''));
    if ($key === '') {
        throw new RuntimeException('Wix API-nyckel saknas på servern.');
    }
    $headers = ['Authorization: ' . $key, 'wix-site-id: ' . $siteId, 'Content-Type: application/json', 'Accept: application/json'];
    if (trim((string) ($sec['account_id'] ?? '')) !== '') {
        $headers[] = 'wix-account-id: ' . trim((string) $sec['account_id']);
    }
    $timeout = 25;
    if (isset($GLOBALS['_JEAB_WIX_TIMEOUT'])) {
        $timeout = max(1, min(10, (int) $GLOBALS['_JEAB_WIX_TIMEOUT']));
    } elseif ($left !== null) {
        $timeout = max(1, min(10, (int) ceil($left)));
    }
    $ch = curl_init('https://www.wixapis.com' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    }
    if (function_exists('jeab_pdo_release_if_idle')) {
        jeab_pdo_release_if_idle();
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if ($code >= 400 || !is_array($data)) {
        throw new RuntimeException('Wix API HTTP ' . $code);
    }
    return $data;
}

function jeab_webshop_wix_order(string $siteId, string $orderId): array
{
    $d = jeab_webshop_wix_request($siteId, 'GET', '/ecom/v1/orders/' . rawurlencode($orderId));
    $o = $d['order'] ?? null;
    if (!is_array($o) || trim((string) ($o['number'] ?? '')) === '') {
        throw new RuntimeException('Wix-ordern hittades inte.');
    }
    return $o;
}

/** En order med det numret, eller null om sökningen inte träffar. */
function jeab_webshop_wix_order_by_number(string $siteId, string $number): ?array
{
    $number = trim($number);
    if ($number === '' || preg_match('/^\d{1,12}$/', $number) !== 1) {
        return null;
    }
    $d = jeab_webshop_wix_request($siteId, 'POST', '/ecom/v1/orders/search', ['search' => [
        'filter' => ['number' => ['$eq' => (int) $number]],
        'cursorPaging' => ['limit' => 5],
    ]]);
    foreach ((array) ($d['orders'] ?? []) as $o) {
        if (is_array($o) && (string) ($o['number'] ?? '') === $number) {
            return $o;
        }
    }
    return null;
}

/**
 * Hämtar ordern på numret. Saknas nyckel, eller om anropet fallerar, blir det null
 * så mejlet kan användas i stället. Nyckeln lämnar inte servern.
 */
function jeab_webshop_wix_lookup_number(string $company, string $number): ?array
{
    $site = array_search($company, JEAB_WEBSHOP_SITES, true);
    if (!is_string($site)) {
        return null;
    }
    $stub = isset($GLOBALS['_JEAB_WIX_REQUEST']) && is_callable($GLOBALS['_JEAB_WIX_REQUEST']);
    if (!$stub && trim((string) (jeab_webshop_secret_read('wix-api.json')['api_key'] ?? '')) === '') {
        return null;
    }
    try {
        return jeab_webshop_wix_order_by_number($site, $number);
    } catch (Throwable) {
        return null;
    }
}

/** Högst så många sidor à 100 per ikappkörning. Nästa körning fortsätter på cursorn. */
const JEAB_WEBSHOP_CATCHUP_PAGE_CAP = 20;

function jeab_webshop_wix_next_cursor(array $data): string
{
    $meta = $data['metadata'] ?? ($data['pagingMetadata'] ?? null);
    if (!is_array($meta)) {
        return '';
    }
    $cursors = $meta['cursors'] ?? null;
    if (!is_array($cursors)) {
        return '';
    }

    return trim((string) ($cursors['next'] ?? ''));
}

/**
 * En sida betalda ordrar. Tom cursor är första sidan.
 *
 * @return array{orders:list<array<string,mixed>>,cursor:string}
 */
function jeab_webshop_wix_paid_page(string $siteId, string $since, string $cursor = ''): array
{
    $paging = ['limit' => 100];
    if ($cursor !== '') {
        $paging['cursor'] = $cursor;
    }
    $d = jeab_webshop_wix_request($siteId, 'POST', '/ecom/v1/orders/search', ['search' => [
        'filter' => ['$and' => [['paymentStatus' => 'PAID'], ['createdDate' => ['$gte' => $since]]]],
        'sort' => [['fieldName' => 'createdDate', 'order' => 'DESC']],
        'cursorPaging' => $paging,
    ]]);
    $orders = array_values(array_filter((array) ($d['orders'] ?? []), 'is_array'));

    return ['orders' => $orders, 'cursor' => jeab_webshop_wix_next_cursor($d)];
}

/**
 * @param array<string, string> $cursors
 * @return array{orders:list<array<string,mixed>>,cursor:string,capped:bool,stopped:bool,pages:list<array{orders:list<array<string,mixed>>,start:string,next:string}>}
 */
function jeab_webshop_wix_paid_since(string $siteId, string $since, array $cursors = []): array
{
    $cursor = trim((string) ($cursors[$siteId] ?? ''));
    $orders = [];
    $pageRows = [];
    $pages = 0;
    $capped = false;
    $stopped = false;
    while (true) {
        $left = jeab_webshop_catchup_time_left();
        if ($left !== null && $left < 0.2) {
            $stopped = true;
            break;
        }
        $pageStart = $cursor;
        $page = jeab_webshop_wix_paid_page($siteId, $since, $cursor);
        $pages++;
        foreach ($page['orders'] as $order) {
            $orders[] = $order;
        }
        $next = $page['cursor'];
        $pageRows[] = ['orders' => $page['orders'], 'start' => $pageStart, 'next' => $next];
        if ($next === '' || $next === $cursor) {
            $cursor = '';
            break;
        }
        $cursor = $next;
        if ($pages >= JEAB_WEBSHOP_CATCHUP_PAGE_CAP) {
            $capped = true;
            break;
        }
    }

    return ['orders' => $orders, 'cursor' => $cursor, 'capped' => $capped, 'stopped' => $stopped, 'pages' => $pageRows];
}

/** Efter ingest: skriv till Fortnox (förkollen länkar om ordern redan finns). */
function jeab_webshop_try_fortnox(int $rid): array
{
    $p = $rid > 0 ? jeab_purchase_get($rid) : null;
    if (!is_array($p) || trim((string) ($p['fortnox_order'] ?? '')) !== '') {
        return ['fortnox_order' => (string) ($p['fortnox_order'] ?? '')];
    }
    try {
        $full = function_exists('jeab_ordrar_backfill_lookup') ? jeab_ordrar_backfill_lookup($p, null, false) : $p;
        $missing = jeab_ordrar_fortnox_missing($full);
        if ($missing) {
            return ['fortnox_missing' => $missing];
        }
        $row = jeab_ordrar_create_fortnox(['id' => $rid]);
        return ['fortnox_order' => (string) ($row['fortnox_order'] ?? '')];
    } catch (Throwable $e) {
        return ['fortnox_error' => $e->getMessage()];
    }
}

/** Hämtar ordern från Wix och kör vanliga webshop-flödet. */
function jeab_webshop_fetch_and_ingest(JeabStore $store, string $siteId, string $orderId): array
{
    $order = jeab_webshop_wix_order($siteId, $orderId);
    if (strtoupper((string) ($order['paymentStatus'] ?? '')) !== 'PAID') {
        return ['ok' => true, 'skipped' => 'not_paid', 'number' => (string) $order['number']];
    }
    $res = jeab_webshop_ingest($store, ['order' => $order, 'site_id' => $siteId]);
    if (!empty($res['ok']) && empty($res['skipped'])) {
        $rid = (int) ($res['reminder_id'] ?? 0);
        if ($rid > 0 && function_exists('jeab_webshop_sole_bind') && function_exists('jeab_webshop_normalize')) {
            try {
                jeab_webshop_sole_bind($store, $rid, jeab_webshop_normalize(['order' => $order, 'site_id' => $siteId]));
            } catch (Throwable) {
                // Ingest är redan accepterad. Nästa ikappkörning försöker bindningen igen.
                if (function_exists('jeab_webshop_sole_bind_mark_pending')) {
                    jeab_webshop_sole_bind_mark_pending($rid);
                }
                if (function_exists('jeab_log_app_error')) {
                    jeab_log_app_error('webshop', 'sole-bind misslyckades');
                }
            }
        }
        $res += jeab_webshop_try_fortnox($rid);
    }
    return $res;
}

/** Daglig ikappkörning: betalda ordrar senaste $days dagar på båda sajterna. */
/**
 * @param array<string, string> $cursors sparad Wix-cursor per sajt
 */
function jeab_webshop_catchup(JeabStore $store, bool $dry = true, int $days = 2, array $cursors = []): array
{
    // Lätta sidor parsar inte order- och inkorgsmodulerna. Ikappkörningen
    // laddar dem här, efter svaret, bara den dag den faktiskt körs.
    foreach (['webshop.php', 'reminders.php', 'purchase.php', 'purchase_store.php', 'ordrar.php', 'fortnox.php'] as $jeabCatchupFile) {
        $jeabCatchupPath = __DIR__ . '/' . $jeabCatchupFile;
        if (is_file($jeabCatchupPath)) {
            require_once $jeabCatchupPath;
        }
    }
    unset($jeabCatchupFile, $jeabCatchupPath);
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400);
    $index = jeab_webshop_index();
    $out = ['ok' => true, 'dry' => $dry, 'since' => $since, 'sites' => []];
    foreach (JEAB_WEBSHOP_SITES as $siteId => $company) {
        $row = ['company' => $company, 'site_id' => $siteId, 'paid' => 0, 'known' => [], 'missing' => [], 'done' => []];
        $left = jeab_webshop_catchup_time_left();
        if ($left !== null && $left < 0.2) {
            $row['error'] = 'tid';
            $out['ok'] = false;
            $out['sites'][] = $row;
            break;
        }
        // Cursorn pekar på en sida. Den flyttas först när sidan är igenom,
        // så ett kast mitt i sidan gör att nästa tick tar om samma sida.
        $resumeCursor = '';
        try {
            $pack = jeab_webshop_wix_paid_since($siteId, $since, $cursors);
            if (!empty($pack['stopped'])) {
                $row['error'] = 'tid';
                $out['ok'] = false;
            }
            $pageList = is_array($pack['pages'] ?? null) ? $pack['pages'] : [];
            if ($pageList === []) {
                $pending = trim((string) ($pack['cursor'] ?? ''));
                if ($pending !== '') {
                    $resumeCursor = $pending;
                }
            }
            $failed = false;
            foreach ($pageList as $page) {
                if (!is_array($page)) {
                    continue;
                }
                $pageStart = trim((string) ($page['start'] ?? ''));
                $pageOrders = is_array($page['orders'] ?? null) ? $page['orders'] : [];
                try {
                    foreach ($pageOrders as $o) {
                        if (!is_array($o)) {
                            continue;
                        }
                        $row['paid']++;
                        $num = (string) ($o['number'] ?? '');
                        $knownKey = jeab_webshop_key($company, $num);
                        if (isset($index[$knownKey])) {
                            $row['known'][] = $num;
                            if (!$dry && function_exists('jeab_webshop_sole_bind_retry')) {
                                $knownRid = (int) ($index[$knownKey]['reminder_id'] ?? 0);
                                jeab_webshop_sole_bind_retry($store, $knownRid, $o, $siteId);
                            }
                            continue;
                        }
                        $row['missing'][] = $num;
                        if (!$dry) {
                            $r = jeab_webshop_fetch_and_ingest($store, $siteId, (string) ($o['id'] ?? ''));
                            $row['done'][] = ['number' => $num, 'reminder_id' => (int) ($r['reminder_id'] ?? 0)];
                        }
                    }
                } catch (Throwable $e) {
                    $failed = true;
                    $row['error'] = $e->getMessage();
                    if ($e->getMessage() === 'tid') {
                        $out['ok'] = false;
                    }
                    $resumeCursor = $pageStart;
                    break;
                }
                $next = trim((string) ($page['next'] ?? ''));
                $resumeCursor = ($next === '' || $next === $pageStart) ? '' : $next;
            }
            if (!$failed && !empty($pack['stopped'])) {
                $pending = trim((string) ($pack['cursor'] ?? ''));
                if ($pending !== '') {
                    $resumeCursor = $pending;
                }
            }
            if ($resumeCursor !== '') {
                $row['cursor'] = $resumeCursor;
            }
        } catch (Throwable $e) {
            $row['error'] = $e->getMessage();
            if ($e->getMessage() === 'tid') {
                $out['ok'] = false;
            }
            $incoming = trim((string) ($cursors[$siteId] ?? ''));
            if ($incoming !== '') {
                $row['cursor'] = $incoming;
            }
        }
        $out['sites'][] = $row;
        if ((string) ($row['error'] ?? '') === 'tid') {
            break;
        }
    }
    return $out;
}

/**
 * En gång per Stockholm-dygn, från tick. En sidvisning anropar inte den här
 * och registrerar ingen shutdown. Spärr: lagrat datum + lås.
 */
function jeab_webshop_catchup_daily_schedule(JeabStore $store): void
{
    if (empty($GLOBALS['_JEAB_TICK'])) {
        return;
    }
    if (trim((string) (jeab_webshop_secret_read('wix-api.json')['api_key'] ?? '')) === '') {
        return;
    }
    if (!function_exists('jeab_webshop_catchup_read')) {
        require_once __DIR__ . '/biz_doc_store.php';
    }
    $p = jeab_data_dir() . '/webshop-catchup.json';
    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
    $catchupDoc = jeab_webshop_catchup_read();
    $last = (string) ($catchupDoc['date'] ?? '');
    if ($last === $today) {
        return;
    }
    $savedCursors = [];
    if (is_array($catchupDoc['cursors'] ?? null)) {
        foreach ($catchupDoc['cursors'] as $siteKey => $savedCursor) {
            if (is_string($siteKey) && is_string($savedCursor) && $savedCursor !== '') {
                $savedCursors[$siteKey] = $savedCursor;
            }
        }
    }
    $run = static function () use ($p, $today, $store, $savedCursors): void {
        $lock = @fopen($p . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return;
        }
        try {
            $cur = (string) (jeab_webshop_catchup_read()['date'] ?? '');
            if ($cur === $today) {
                return;
            }
            $left = jeab_webshop_catchup_time_left();
            if ($left !== null && $left < 0.2) {
                return;
            }
            if ($left !== null && PHP_SAPI !== 'cli') {
                @set_time_limit(max(1, (int) ceil(min(10.0, $left))));
            }
            $res = jeab_webshop_catchup($store, false, 2, $savedCursors);
            $resume = [];
            $siteError = false;
            foreach ($res['sites'] as $s) {
                if (!is_array($s)) {
                    continue;
                }
                if (trim((string) ($s['error'] ?? '')) !== '') {
                    $siteError = true;
                }
                $siteKey = (string) ($s['site_id'] ?? '');
                $nextCursor = trim((string) ($s['cursor'] ?? ''));
                if ($siteKey !== '' && $nextCursor !== '') {
                    $resume[$siteKey] = $nextCursor;
                }
            }
            if (jeab_webshop_catchup_stopped_for_time($res) || $resume !== [] || $siteError) {
                if ($resume !== []) {
                    $partial = ['date' => '', 'at' => time(), 'cursors' => $resume];
                    if (jeab_biz_doc_use_sql('webshop_catchup')) {
                        jeab_webshop_catchup_write($partial);
                    } else {
                        @file_put_contents($p, json_encode($partial), LOCK_EX);
                    }
                }
                return;
            }
            $sum = [];
            foreach ($res['sites'] as $s) {
                $sum[$s['company']] = ['paid' => $s['paid'], 'added' => count($s['done']), 'error' => $s['error'] ?? ''];
            }
            $done = ['date' => $today, 'at' => time(), 'result' => $sum, 'cursors' => []];
            if (jeab_biz_doc_use_sql('webshop_catchup')) {
                jeab_webshop_catchup_write($done);
            } else {
                @file_put_contents($p, json_encode($done), LOCK_EX);
            }
        } catch (Throwable $e) {
            if (function_exists('jeab_log_app_error')) {
                jeab_log_app_error('webshop', $e::class);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    };
    $run();
}
