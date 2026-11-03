<?php

declare(strict_types=1);

/**
 * PR 17: H-SMS-2, H-WEB-1, H-WEB-2, H-WEB-3, H-FUL-1, H-FUL-2, H-DB-1, H-DB-2, H-TICK-1.
 * Påhittade rader. Inga personnummer.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-pr17-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0700, true);
mkdir($tmp . '/fullmakt', 0700, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_STEG4_CUTOVER'] = true;
$GLOBALS['_JEAB_DATA_BACKEND'] = 'mysql';
$GLOBALS['JEAB_NO_SESSION'] = true;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('FULLMAKT_DATA=' . $tmp . '/fullmakt');
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
$_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
file_put_contents($tmp . '/inkorg/messages.json', '{"messages":[]}');
file_put_contents($tmp . '/inkorg/threads.json', '{"threads":{}}');
file_put_contents($tmp . '/secrets/wix-api.json', json_encode(['api_key' => 'test-nyckel']));

require $root . '/src/bootstrap.php';
$GLOBALS['_JEAB_LOG_PDO'] = new PDO('sqlite::memory:');
$GLOBALS['_JEAB_LOG_PDO']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
jeab_log_pdo();
jeab_log_flag_set($GLOBALS['_JEAB_LOG_PDO'], 'webshop_orders');
require $root . '/fullmakt/lib/bootstrap.php';
require $root . '/fullmakt/lib/store.php';
if (!function_exists('jeab_data_job_heartbeat')) {
    require_once $root . '/src/data_jobs.php';
}
fullmakt_boot();

$fail = 0;
function pr17(bool $ok, string $message): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $message . "\n";
    if (!$ok) {
        $fail++;
    }
}

// H-DB-2 och H-TICK-1: låskatalog som inte går att skapa är upptaget, inte öppet.
$blocker = $tmp . '/not-a-directory';
file_put_contents($blocker, 'x');
$savedDataDir = $GLOBALS['_JEAB_DATA_DIR'];
$GLOBALS['_JEAB_DATA_DIR'] = $blocker;
$blockedSlot = jeab_db_slot_acquire(1);
$blockedOverlap = jeab_job_overlap_try();
$GLOBALS['_JEAB_DATA_DIR'] = $savedDataDir;
pr17($blockedSlot === null, 'H-DB-2 katalog som inte kan skapas ger null, fick ' . get_debug_type($blockedSlot));
pr17($blockedOverlap === false, 'H-TICK-1 katalog som inte kan skapas ger false');

$slotDir = jeab_db_slot_dir();
mkdir($slotDir . '/slot-0.lock');
mkdir($slotDir . '/slot-1.lock');
$fopenSlot = jeab_db_slot_acquire(2);
pr17($fopenSlot === null, 'H-DB-2 låsfil som inte kan öppnas ger null');
rmdir($slotDir . '/slot-0.lock');
rmdir($slotDir . '/slot-1.lock');
$held = jeab_db_slot_acquire(1);
pr17(is_resource($held), 'H-DB-2 en ledig lucka är en resurs');
if (is_resource($held)) {
    jeab_db_slot_release($held);
}

mkdir($slotDir . '/job-overlap.lock');
pr17(jeab_job_overlap_try() === false, 'H-TICK-1 låsfil som inte kan öppnas ger false');
rmdir($slotDir . '/job-overlap.lock');
pr17(jeab_job_overlap_acquire() === true, 'H-TICK-1 första processen tar låset');
pr17(jeab_job_overlap_acquire() === true, 'H-TICK-1 samma process får nästla');
jeab_job_overlap_release();
jeab_job_overlap_release();
pr17(jeab_job_overlap_try() === true, 'H-TICK-1 låset kan tas igen efter släpp');
jeab_job_overlap_release();

// H-DB-1: misslyckat commit syns. Låset är inte sparat.
$jobFile = $tmp . '/jobs.sqlite';
$jobOk = new PDO('sqlite:' . $jobFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
jeab_ensure_data_jobs($jobOk, 'sqlite');
$now = jeab_data_job_now();
$insertJob = static function (PDO $pdo, string $jobId) use ($now): void {
    $pdo->prepare(
        'INSERT INTO data_jobs (job_id, job_type, status, progress_json, state_json, result_json, payload_json, heartbeat_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$jobId, 'data.migrate_all', 'running', '{}', '{}', null, '{}', $now, $now, $now]);
};
$insertJob($jobOk, 'job-ok');
pr17(jeab_data_job_heartbeat($jobOk, 'sqlite', 'data.migrate_all', 'job-ok') === true, 'H-DB-1 lyckat hjärtslag sparas');
$seenOk = (new PDO('sqlite:' . $jobFile))->query("SELECT job_id FROM data_job_locks WHERE job_type = 'data.migrate_all'")->fetchColumn();
pr17($seenOk === 'job-ok', 'H-DB-1 låsraden syns i en annan anslutning');

$jobFail = new class('sqlite:' . $tmp . '/jobs-fail.sqlite') extends PDO {
    public function exec(string $statement): int|false
    {
        if (preg_match('/^\s*COMMIT\b/i', $statement) === 1) {
            throw new PDOException('commit fail');
        }

        return parent::exec($statement);
    }
};
$jobFail->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$jobFail->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
jeab_ensure_data_jobs($jobFail, 'sqlite');
$insertJob($jobFail, 'job-fail');
pr17(jeab_data_job_heartbeat($jobFail, 'sqlite', 'data.migrate_all', 'job-fail') === false, 'H-DB-1 misslyckat commit ger false');
$seenFail = (new PDO('sqlite:' . $tmp . '/jobs-fail.sqlite'))->query('SELECT COUNT(*) FROM data_job_locks')->fetchColumn();
pr17((int) $seenFail === 0, 'H-DB-1 låsraden committas inte när commit faller, fick ' . (string) $seenFail);

// H-WEB-2: träffar räknas i SQL.
$ratePdo = jeab_omtago_pdo();
pr17($ratePdo instanceof PDO, 'H-WEB-2 rate-tabellen finns');
if ($ratePdo instanceof PDO) {
    $mysqlSql = jeab_omtago_rate_upsert_sql('mysql');
    $sqliteSql = jeab_omtago_rate_upsert_sql('sqlite');
    pr17(!str_contains($mysqlSql, 'ON CONFLICT') && str_contains($mysqlSql, 'hits + 1'), 'H-WEB-2 mysql ökar hits i SQL');
    pr17(str_contains($sqliteSql, 'ON CONFLICT') && str_contains($sqliteSql, 'hits + 1'), 'H-WEB-2 sqlite ökar hits i SQL');
    $oldIp = '203.0.113.77';
    $oldHash = hash('sha256', $oldIp);
    $ratePdo->prepare('INSERT INTO omtago_rate (ip_hash, window_start, hits) VALUES (?, ?, ?)')->execute([$oldHash, time() - 120, 5]);
    $reset = jeab_omtago_rate_gate($ratePdo, $oldIp);
    $resetHits = (int) $ratePdo->query('SELECT hits FROM omtago_rate WHERE ip_hash = ' . $ratePdo->quote($oldHash))->fetchColumn();
    pr17($reset === null && $resetHits === 1, 'H-WEB-2 nytt fönster nollställer träffarna, fick ' . $resetHits);
    $GLOBALS['_JEAB_OMTAGO_RATE_MAX'] = 2;
    $ip = '203.0.113.78';
    $hash = hash('sha256', $ip);
    $first = jeab_omtago_rate_gate($ratePdo, $ip);
    $second = jeab_omtago_rate_gate($ratePdo, $ip);
    $third = jeab_omtago_rate_gate($ratePdo, $ip);
    $hits = (int) $ratePdo->query('SELECT hits FROM omtago_rate WHERE ip_hash = ' . $ratePdo->quote($hash))->fetchColumn();
    unset($GLOBALS['_JEAB_OMTAGO_RATE_MAX']);
    pr17($first === null && $second === null, 'H-WEB-2 två anrop under taket släpps');
    pr17(is_array($third) && ($third['status'] ?? 0) === 429, 'H-WEB-2 tredje anropet är 429');
    pr17($hits === 3, 'H-WEB-2 lagrat antal är 3, fick ' . $hits);
}

// H-SMS-2: anspråk före utskick. Avsiktligt avbrott är inte ett fel.
$starts = '2026-10-28 10:00';
pr17(jeab_sms_channel_claim_decision(['sms' => true, 'starts_at' => $starts], '2026-10-28 11:00', 'sms') === 'send', 'H-SMS-2 ny tid får skickas');
pr17(jeab_sms_channel_claim_decision(['sms' => true, 'starts_at' => $starts], $starts, 'sms') === 'sent', 'H-SMS-2 samma tid är redan skickad');
pr17(jeab_sms_channel_claim_decision(['email' => true], $starts, 'email') === 'sent', 'H-SMS-2 mejl skickas en gång');
$claimId = 770017;
pr17(jeab_sms_channel_claim($claimId, $starts, 'sms') === 'send', 'H-SMS-2 första anspråket får skicka');
pr17(jeab_sms_channel_claim($claimId, $starts, 'sms') === 'held', 'H-SMS-2 andra anspråket väntar');
jeab_sms_channel_release($claimId, 'sms', $starts);
pr17(jeab_sms_channel_claim($claimId, $starts, 'sms') === 'send', 'H-SMS-2 släppt anspråk får skicka igen');
$recorded = jeab_sms_record_sent($claimId, [
    'sms' => true,
    'sms_at' => '2026-10-28 10:01',
    'starts_at' => $starts,
], ['id' => $claimId], 'sms');
pr17($recorded === true, 'H-SMS-2 flaggan sparas efter utskick');
pr17(jeab_sms_channel_claim($claimId, $starts, 'sms') === 'sent', 'H-SMS-2 sparad flagga skickar inte igen');
pr17(jeab_sms_store_update(770018, static function (): array {
    throw new RuntimeException('jeab-sms-skip');
}) === true, 'H-SMS-2 avsiktligt avbrott är inte ett fel');
pr17(jeab_sms_store_update(770018, static function (): array {
    throw new RuntimeException('disk');
}) === false, 'H-SMS-2 ett riktigt skrivfel returnerar false');
$claimPath = jeab_sms_claim_file_path(770019, 'email', $starts);
pr17(is_string($claimPath) && !str_ends_with($claimPath, '.json'), 'H-SMS-2 filanspråk är ingen json-fil');
pr17(jeab_sms_channel_claim_file(770019, $starts, 'email') === 'send', 'H-SMS-2 filanspråk tar sändningen');
pr17(jeab_sms_channel_claim_file(770019, $starts, 'email') === 'held', 'H-SMS-2 filanspråk hålls');
jeab_sms_channel_release_file(770019, 'email', $starts);
jeab_sms_channel_file_mark_sent(770019, 'email', $starts);
pr17(jeab_sms_channel_claim_file(770019, $starts, 'email') === 'sent', 'H-SMS-2 filanspråk kan markeras skickat');
pr17(!is_file(jeab_sms_sent_path()), 'H-SMS-2 sms-sent.json skrivs inte');

// Misslyckad sparning efter ok från leverantören får inte skicka samma bokning igen.
$failId = 770099;
$failStarts = '2026-10-28 12:00';
pr17(jeab_sms_channel_claim($failId, $failStarts, 'sms') === 'send', 'H-SMS-2 anspråk före utskick som inte kan sparas');
$smsPdo = jeab_sms_store_pdo();
pr17($smsPdo instanceof PDO, 'H-SMS-2 sms-databasen finns');
if ($smsPdo instanceof PDO) {
    $smsPdo->exec(
        'CREATE TRIGGER pr17_sms_block_sent BEFORE UPDATE ON sms_sent
         WHEN NEW.booking_id = 770099 AND instr(NEW.payload, \'"sms":true\') > 0
         BEGIN SELECT RAISE(ABORT, \'save fail\'); END'
    );
    $savedAfterSend = jeab_sms_record_sent($failId, [
        'sms' => true,
        'sms_at' => '2026-10-28 12:01',
        'starts_at' => $failStarts,
    ], ['id' => $failId], 'sms');
    $heldRaw = $smsPdo->query('SELECT payload FROM sms_sent WHERE booking_id = 770099')->fetchColumn();
    $held = is_string($heldRaw) ? json_decode($heldRaw, true) : null;
    pr17($savedAfterSend === false, 'H-SMS-2 sparning efter utskick kan falla');
    pr17(is_array($held) && empty($held['sms']) && (int) ($held['sms_claim_at'] ?? 0) > 0, 'H-SMS-2 anspråket släpps inte när sparningen faller');
    $sentFlag = jeab_sms_claim_file_path($failId, 'sms', $failStarts);
    pr17(is_string($sentFlag) && is_file($sentFlag) && trim((string) file_get_contents($sentFlag)) === 'sent', 'H-SMS-2 filflaggan sätts trots PDO');
    if (is_array($held)) {
        $held['sms_claim_at'] = time() - 700;
        $smsPdo->prepare('UPDATE sms_sent SET payload = ? WHERE booking_id = ?')->execute([
            json_encode($held, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $failId,
        ]);
        pr17(jeab_sms_channel_claim($failId, $failStarts, 'sms') === 'sent', 'H-SMS-2 utgången anspråk skickar inte om efter ok');
    }
    $smsPdo->exec('DROP TRIGGER pr17_sms_block_sent');
}

// H-WEB-1: följ cursor. Dagen stämplas inte medan en cursor återstår.
$siteId = (string) array_key_first(JEAB_WEBSHOP_SITES);
$otherSite = (string) array_key_last(JEAB_WEBSHOP_SITES);
$pageCalls = 0;
$GLOBALS['_JEAB_WIX_CATCHUP_DEADLINE'] = microtime(true) + 30;
$GLOBALS['_JEAB_WIX_REQUEST'] = static function (string $site, string $method, string $path, ?array $body) use (&$pageCalls): array {
    unset($site, $method, $path);
    $pageCalls++;
    $cursor = (string) ($body['search']['cursorPaging']['cursor'] ?? '');
    if ($cursor === '') {
        return ['orders' => [['id' => 'a', 'number' => '1']], 'metadata' => ['cursors' => ['next' => 'resume-a']]];
    }

    return ['orders' => [['id' => 'b', 'number' => '2']], 'metadata' => ['cursors' => ['next' => '']]];
};
$two = jeab_webshop_wix_paid_since($siteId, '2026-10-01T00:00:00Z');
pr17($pageCalls === 2 && count($two['orders']) === 2 && ($two['cursor'] ?? 'x') === '', 'H-WEB-1 två sidor hämtas och cursorn tar slut');

$pageCalls = 0;
$GLOBALS['_JEAB_WIX_REQUEST'] = static function (string $site, string $method, string $path, ?array $body) use (&$pageCalls): array {
    unset($site, $method, $path, $body);
    $pageCalls++;
    $GLOBALS['_JEAB_WIX_CATCHUP_DEADLINE'] = microtime(true) - 5;

    return ['orders' => [['id' => 'a', 'number' => '1']], 'metadata' => ['cursors' => ['next' => 'resume-a']]];
};
$stopped = jeab_webshop_wix_paid_since($siteId, '2026-10-01T00:00:00Z');
pr17($pageCalls === 1 && !empty($stopped['stopped']) && ($stopped['cursor'] ?? '') === 'resume-a', 'H-WEB-1 tidsstopp behåller cursorn till sidan som inte hämtats');

$capCalls = 0;
unset($GLOBALS['_JEAB_WIX_CATCHUP_DEADLINE']);
$GLOBALS['_JEAB_WIX_REQUEST'] = static function (string $site, string $method, string $path, ?array $body) use (&$capCalls): array {
    unset($site, $method, $path, $body);
    $capCalls++;

    return ['orders' => [], 'metadata' => ['cursors' => ['next' => 'c' . $capCalls]]];
};
$capped = jeab_webshop_wix_paid_since($siteId, '2026-10-01T00:00:00Z');
pr17($capCalls === JEAB_WEBSHOP_CATCHUP_PAGE_CAP && !empty($capped['capped']) && ($capped['cursor'] ?? '') === 'c' . JEAB_WEBSHOP_CATCHUP_PAGE_CAP, 'H-WEB-1 taket lämnar en cursor');

$schedCalls = 0;
$GLOBALS['_JEAB_TICK'] = true;
$GLOBALS['_JEAB_WIX_REQUEST'] = static function (string $site, string $method, string $path, ?array $body) use (&$schedCalls): array {
    unset($method, $path);
    $cursor = (string) ($body['search']['cursorPaging']['cursor'] ?? '');
    $schedCalls++;
    $n = $cursor === '' ? 1 : ((int) substr($cursor, 1)) + 1;

    return ['orders' => [], 'metadata' => ['cursors' => ['next' => $site[0] . $n]]];
};
jeab_webshop_catchup_daily_schedule(jeab_store());
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
$doc = jeab_webshop_catchup_read();
pr17($schedCalls === JEAB_WEBSHOP_CATCHUP_PAGE_CAP * 2, 'H-WEB-1 ikappkörningen följer cursor på båda sajterna, fick ' . $schedCalls);
pr17((string) ($doc['date'] ?? '') !== $today && is_array($doc['cursors'] ?? null) && count($doc['cursors']) === 2, 'H-WEB-1 dagen stämplas inte när cursor återstår');
unset($GLOBALS['_JEAB_TICK']);

// Ett kast mitt i sidan får inte hoppa till nästa cursor. Nästa tick tar om sidan.
$seedCursor = 'page-start';
$seed = ['date' => '', 'at' => 1, 'cursors' => [$siteId => $seedCursor]];
$catchPath = $tmp . '/webshop-catchup.json';
if (is_file($catchPath)) {
    unlink($catchPath);
}
if (function_exists('jeab_biz_doc_use_sql') && jeab_biz_doc_use_sql('webshop_catchup')) {
    jeab_webshop_catchup_write($seed);
} else {
    file_put_contents($catchPath, json_encode($seed));
}
$badGets = 0;
$laterGets = 0;
$throwOrder = true;
$GLOBALS['_JEAB_WIX_REQUEST'] = static function (string $site, string $method, string $path, ?array $body) use (&$badGets, &$laterGets, &$throwOrder, $siteId): array {
    unset($method);
    if (str_contains($path, '/ecom/v1/orders/search')) {
        $cursor = (string) ($body['search']['cursorPaging']['cursor'] ?? '');
        if ($site === $siteId && $cursor === 'page-start') {
            return ['orders' => [
                ['id' => 'bad-order', 'number' => '910001'],
                ['id' => 'later-order', 'number' => '910002'],
            ], 'metadata' => ['cursors' => ['next' => 'skip-ahead']]];
        }

        return ['orders' => [], 'metadata' => ['cursors' => ['next' => '']]];
    }
    if (str_contains($path, 'bad-order')) {
        $badGets++;
        if ($throwOrder) {
            throw new RuntimeException('wix order down');
        }

        return ['order' => ['id' => 'bad-order', 'number' => '910001', 'paymentStatus' => 'NOT_PAID']];
    }
    if (str_contains($path, 'later-order')) {
        $laterGets++;

        return ['order' => ['id' => 'later-order', 'number' => '910002', 'paymentStatus' => 'NOT_PAID']];
    }

    return ['orders' => [], 'metadata' => ['cursors' => ['next' => '']]];
};
unset($GLOBALS['_JEAB_WIX_CATCHUP_DEADLINE']);
$failedCatchup = jeab_webshop_catchup(jeab_store(), false, 2, [$siteId => $seedCursor]);
$failedSite = null;
foreach ($failedCatchup['sites'] as $siteRow) {
    if (is_array($siteRow) && (string) ($siteRow['site_id'] ?? '') === $siteId) {
        $failedSite = $siteRow;
    }
}
pr17(is_array($failedSite) && (string) ($failedSite['cursor'] ?? '') === $seedCursor, 'H-WEB-1 fel mitt i sidan behåller sidans cursor, fick ' . (string) ($failedSite['cursor'] ?? ''));
pr17(($failedSite['error'] ?? '') === 'wix order down' && $laterGets === 0, 'H-WEB-1 ordrar efter felet på sidan hoppas inte över i cursorn');
$GLOBALS['_JEAB_TICK'] = true;
jeab_webshop_catchup_daily_schedule(jeab_store());
$heldDoc = jeab_webshop_catchup_read();
pr17((string) ($heldDoc['date'] ?? '') !== $today && (string) (($heldDoc['cursors'][$siteId] ?? '')) === $seedCursor, 'H-WEB-1 ikappkörningen sparar inte cursorn förbi felet');
$throwOrder = false;
jeab_webshop_catchup_daily_schedule(jeab_store());
$retried = jeab_webshop_catchup_read();
pr17($badGets >= 2 && $laterGets === 1 && (string) ($retried['date'] ?? '') === $today, 'H-WEB-1 nästa tick tar om ordern och stämplar dagen först när sidan är klar');
unset($GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_WIX_REQUEST']);

// H-WEB-3: bindning som faller lämnar en flagga. Ingesten står kvar. Nästa ikapp försöker igen.
$order = [
    'id' => 'wix-pr17',
    'number' => '880017',
    'paymentStatus' => 'PAID',
    'status' => 'APPROVED',
    'createdDate' => '2026-10-27T12:00:00Z',
    'buyerInfo' => ['email' => 'pr17@example.test'],
    'billingInfo' => [
        'contactDetails' => ['firstName' => 'Pr', 'lastName' => 'Sjutton', 'phone' => '0700000017'],
        'address' => ['addressLine' => 'Testgatan 1', 'postalCode' => '177 00', 'city' => 'Teststad'],
    ],
    'lineItems' => [['productName' => 'Testartikel', 'quantity' => 1, 'price' => ['amount' => '10']]],
    'priceSummary' => ['total' => ['amount' => '10']],
];
$soleCalls = 0;
$GLOBALS['_JEAB_WEBSHOP_SOLE_BIND'] = static function () use (&$soleCalls): string {
    $soleCalls++;
    // Ingest anropar bindningen före fetch-fångsten. Bara det andra anropet kastar.
    if ($soleCalls < 2) {
        return '';
    }
    throw new RuntimeException('bind down');
};
$GLOBALS['_JEAB_WIX_REQUEST'] = static function (string $site, string $method, string $path, ?array $body) use ($order, $siteId): array {
    unset($method, $body);
    if (!str_contains($path, '/ecom/v1/orders/search') && str_contains($path, '/ecom/v1/orders/')) {
        return ['order' => $order];
    }
    if ($site === $siteId && str_contains($path, '/ecom/v1/orders/search')) {
        return ['orders' => [$order], 'metadata' => ['cursors' => ['next' => '']]];
    }

    return ['orders' => [], 'metadata' => ['cursors' => ['next' => '']]];
};
unset($GLOBALS['_JEAB_WIX_CATCHUP_DEADLINE']);
$ingested = jeab_webshop_fetch_and_ingest(jeab_store(), $siteId, 'wix-pr17');
$rid = (int) ($ingested['reminder_id'] ?? 0);
pr17(!empty($ingested['ok']) && $rid > 0, 'H-WEB-3 ingest är ok trots bindfel, rid ' . $rid);
pr17(jeab_webshop_sole_bind_pending($rid), 'H-WEB-3 bindfel sätter sole_bind_pending');
$beforeDry = $soleCalls;
jeab_webshop_catchup(jeab_store(), true, 2);
pr17($soleCalls === $beforeDry && jeab_webshop_sole_bind_pending($rid), 'H-WEB-3 torr körning försöker inte bindningen igen');
$GLOBALS['_JEAB_WEBSHOP_SOLE_BIND'] = static function () use (&$soleCalls): string {
    $soleCalls++;

    return 'contact-pr17';
};
jeab_webshop_catchup(jeab_store(), false, 2);
pr17($soleCalls === $beforeDry + 1 && !jeab_webshop_sole_bind_pending($rid), 'H-WEB-3 nästa ikapp binder och tar bort flaggan');
unset($GLOBALS['_JEAB_WEBSHOP_SOLE_BIND'], $GLOBALS['_JEAB_WIX_REQUEST']);

// H-FUL-2: mejllänken är fast. Host och proto från anropet styr den inte.
$savedPublic = getenv('FULLMAKT_PUBLIC_URL');
putenv('FULLMAKT_PUBLIC_URL');
unset($_ENV['FULLMAKT_PUBLIC_URL']);
$_SERVER['HTTP_HOST'] = 'evil.example';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
$_SERVER['HTTPS'] = 'off';
pr17(fullmakt_public_base() === 'https://flowstack.example.test/fullmakt', 'H-FUL-2 tom env ger https://flowstack.example.test/fullmakt');
pr17(fullmakt_sign_url('abc') === 'https://flowstack.example.test/fullmakt/s/abc', 'H-FUL-2 signerlänken hänger på den fasta basen');
putenv('FULLMAKT_PUBLIC_URL=https://flowstack.example.test/fullmakt/');
pr17(fullmakt_public_base() === 'https://flowstack.example.test/fullmakt', 'H-FUL-2 satt env trimmas');
if (is_string($savedPublic) && $savedPublic !== '') {
    putenv('FULLMAKT_PUBLIC_URL=' . $savedPublic);
} else {
    putenv('FULLMAKT_PUBLIC_URL');
}
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';
pr17(fullmakt_client_ip() === '203.0.113.9', 'H-FUL-2 klient-IP läser REMOTE_ADDR');
$signSrc = (string) file_get_contents($root . '/fullmakt/sign.php');
pr17(str_contains($signSrc, "!empty(\$signed['signed_winner'])"), 'H-FUL-1 kundkopia bara från vinnaren');

// H-FUL-1: exklusivt PDF-lås. Förloraren skriver inte över en fil som finns.
$token = 'pr17pdf' . bin2hex(random_bytes(3));
$pdfPath = fullmakt_signed_pdf_path($token);
$lockFh = fopen($pdfPath . '.lock', 'c');
pr17(is_resource($lockFh) && flock($lockFh, LOCK_EX), 'H-FUL-1 testet tar PDF-låset');
$childScript = $tmp . '/pdf-child.php';
file_put_contents($childScript, <<<'PHP'
<?php
declare(strict_types=1);
$token = $argv[1];
$data = $argv[2];
$root = $argv[3];
putenv('FULLMAKT_DATA=' . $data);
require $root . '/fullmakt/lib/bootstrap.php';
require $root . '/fullmakt/lib/store.php';
fullmakt_write_signed_pdf($token, "%PDF-1.4 child\n");
echo "wrote\n";
PHP);
$pipes = [];
$proc = proc_open(
    [PHP_BINARY, $childScript, $token, (string) getenv('FULLMAKT_DATA'), $root],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
pr17(is_resource($proc), 'H-FUL-1 barnprocess startar');
if (is_resource($proc)) {
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    usleep(400000);
    $running = proc_get_status($proc);
    pr17(!empty($running['running']) && !is_file($pdfPath), 'H-FUL-1 barnet väntar och skriver inte under låset');
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    $childOut = '';
    $childErr = '';
    $deadline = microtime(true) + 8;
    do {
        $childOut .= (string) stream_get_contents($pipes[1]);
        $childErr .= (string) stream_get_contents($pipes[2]);
        $running = proc_get_status($proc);
        if (empty($running['running'])) {
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    pr17(is_file($pdfPath) && str_starts_with((string) file_get_contents($pdfPath), '%PDF-1.4 child'), 'H-FUL-1 barnet skriver när låset släpps' . ($childErr !== '' ? ' err=' . $childErr : ''));
}
$nested = 'pr17nest' . bin2hex(random_bytes(3));
fullmakt_signed_pdf_with_lock($nested, static function () use ($nested): void {
    fullmakt_write_signed_pdf($nested, "%PDF-1.4 nested\n");
});
pr17(str_starts_with((string) @file_get_contents(fullmakt_signed_pdf_path($nested)), '%PDF-1.4 nested'), 'H-FUL-1 nästlat lås skriver utan dödläge');
$existing = 'pr17keep' . bin2hex(random_bytes(3));
file_put_contents(fullmakt_signed_pdf_path($existing), 'winner-bytes');
fullmakt_write_signed_pdf($existing, 'loser-bytes');
pr17(file_get_contents(fullmakt_signed_pdf_path($existing)) === 'winner-bytes', 'H-FUL-1 befintlig PDF skrivs inte över');

if (fullmakt_sql_active()) {
    $created = fullmakt_create_doc([
        'type' => 'overlay',
        'stamp_method' => 'fpdi',
        'namn' => 'Pr Sjutton',
        'epost' => 'pr17.fullmakt@example.test',
        'telefon' => '0700000017',
    ]);
    $sqlToken = (string) ($created['token'] ?? '');
    $winner = $sqlToken !== '' ? fullmakt_mark_signed($sqlToken, [], "%PDF-1.4 winner\n") : [];
    $winnerBytes = $sqlToken !== '' ? (string) @file_get_contents(fullmakt_signed_pdf_path($sqlToken)) : '';
    $loser = $sqlToken !== '' ? fullmakt_mark_signed($sqlToken, [], "%PDF-1.4 loser\n") : [];
    pr17(!empty($winner['signed_winner']), 'H-FUL-1 SQL-vinnaren märks signed_winner');
    pr17(empty($loser['signed_winner']) && ($loser['status'] ?? '') === 'signed', 'H-FUL-1 SQL-förloraren mejlar inte');
    pr17($winnerBytes !== '' && file_get_contents(fullmakt_signed_pdf_path($sqlToken)) === $winnerBytes, 'H-FUL-1 förloraren skriver inte över vinnarens PDF');
} else {
    pr17(false, 'H-FUL-1 SQL-vägen är inte aktiv');
}

echo $fail === 0 ? "ALL OK\n" : "FAILS {$fail}\n";
exit($fail === 0 ? 0 : 1);
