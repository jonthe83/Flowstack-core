<?php

declare(strict_types=1);

/**
 * Sidvisning av Utskick skapar ingen tabell och läser inte mejlboxen.
 * Tick fyller historiken. Sol A och B delar inte en gissad tid.
 * Ingenting skickas.
 */

$tmp = sys_get_temp_dir() . '/jeab-utskick-sid-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa testkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
$GLOBALS['JEAB_GRAPH_SEND'] = static function (): array {
    fwrite(STDERR, "FAIL graph skickades\n");
    exit(1);
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = $GLOBALS['JEAB_GRAPH_SEND'];
$GLOBALS['JEAB_SMS_SEND'] = $GLOBALS['JEAB_GRAPH_SEND'];

require dirname(__DIR__) . '/src/bootstrap.php';

function sv(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function sv_light(bool $on): void
{
    if ($on) {
        $GLOBALS['_JEAB_PAGE_REQUEST'] = 1;
        $GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
        unset($GLOBALS['_JEAB_MIGRATE_RUNS'], $GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_SCHEMA_DDL'], $GLOBALS['_JEAB_NOTIS_SEND_DDL']);
        return;
    }
    unset($GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_SAPI']);
}

function sv_table(string $name): bool
{
    $pdo = jeab_store()->pdo();
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($name));
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

    return is_array($row);
}

function sv_row(string $id): array
{
    foreach (jeab_notiser_catalog() as $row) {
        if (($row['id'] ?? '') === $id) {
            return $row;
        }
    }

    return [];
}

file_put_contents($GLOBALS['_JEAB_CALENDAR_LOG_PATH'], json_encode(['entries' => [], 'backfilled' => false]));
file_put_contents(jeab_inkorg_dir() . '/threads.json', json_encode(['threads' => [
    't-ladd' => [
        'status' => 'closed',
        'tags' => [],
        'quote_sent' => ['source' => 'Inkorg', 'at' => '2026-10-03 12:00:00', 'date' => '2026-10-03 12:00', 'product' => 'laddbox'],
        'drafts' => [[
            'id' => 'n-ladd',
            'kind' => 'note',
            'body' => 'Offert (Elbilsladdare) skickad.',
            'created_at' => '2026-10-03 12:00:00',
        ]],
    ],
    't-okand' => [
        'status' => 'closed',
        'tags' => [],
        'labels' => ['offer' => 'sol'],
        'quote_sent' => ['source' => 'Inkorg', 'at' => '2026-10-01 10:00:00', 'date' => '2026-10-01 10:00', 'product' => 'sol'],
        'drafts' => [[
            'id' => 'n-okand',
            'kind' => 'note',
            'body' => 'Offert (Solceller) skickad.',
            'created_at' => '2026-10-01 10:00:00',
        ]],
    ],
    't-kopt' => [
        'status' => 'closed',
        'tags' => [],
        'labels' => ['offer' => 'sol', 'lead' => 'kopt'],
        'quote_sent' => ['source' => 'Inkorg', 'at' => '2026-10-02 11:00:00', 'date' => '2026-10-02 11:00', 'product' => 'sol', 'variant' => 'kopt'],
        'drafts' => [[
            'id' => 'n-kopt',
            'kind' => 'note',
            'body' => 'Offert (Solceller) skickad.',
            'created_at' => '2026-10-02 11:00:00',
        ]],
    ],
]], JSON_UNESCAPED_UNICODE));
file_put_contents(jeab_inkorg_dir() . '/messages.json', json_encode(['messages' => [
    ['id' => 'm-ladd', 'thread_id' => 't-ladd', 'from' => 'a@testform.test', 'to' => 'kontakt@jarfalla.example.test', 'subject' => 'Elbil', 'body' => 'Hej', 'received_at' => '2026-10-03 12:00:00', 'labels' => ['offer' => 'laddbox'], 'source' => 'test'],
    ['id' => 'm-okand', 'thread_id' => 't-okand', 'from' => 'a@testform.test', 'to' => 'kontakt@jarfalla.example.test', 'subject' => 'Sol', 'body' => 'Hej', 'received_at' => '2026-10-01 10:00:00', 'labels' => ['offer' => 'sol'], 'source' => 'test'],
    ['id' => 'm-kopt', 'thread_id' => 't-kopt', 'from' => 'a@testform.test', 'to' => 'kontakt@jarfalla.example.test', 'subject' => 'Sol', 'body' => 'Hej', 'received_at' => '2026-10-02 11:00:00', 'labels' => ['offer' => 'sol', 'lead' => 'kopt'], 'source' => 'test'],
]], JSON_UNESCAPED_UNICODE));

$pdo = jeab_store()->pdo();
sv($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite', 'testet använder sqlite');
$pdo->exec('DROP TABLE IF EXISTS notis_sends');
$pdo->exec('DROP TABLE IF EXISTS notis_history');
sv_light(true);
sv(jeab_notiser_view_is_light(), 'sidvisning är lätt');
sv(jeab_notiser_quote_event_ids('sol', 'jarfalla', '', true) === [], 'okänd solvariant ger ingen rad');
sv(jeab_notiser_quote_event_ids('sol', 'jarfalla', 'kopt', true) === ['offert-sol-jarfalla-kopt'], 'köpt lead är bara mall A');
sv(jeab_notiser_quote_event_ids('sol', 'jarfalla', 'eget', true) === ['offert-sol-jarfalla-eget'], 'egen lead är bara mall B');

$GLOBALS['_JEAB_NOTIS_HEAVY'] = 0;
$before = sv_row('offert-laddbox-jarfalla');
sv(($before['last_used'] ?? '') === '', 'sidan läser inte anteckningen, fick ' . ($before['last_used'] ?? ''));
sv((int) $GLOBALS['_JEAB_NOTIS_HEAVY'] === 0, 'sidan skannar inte mejlboxen');
sv(!sv_table('notis_sends') && !sv_table('notis_history'), 'sidan skapar inte tabellerna');

jeab_notiser_record_send('offert-laddbox-jarfalla', true, false, '', '2026-10-28 09:00:00');
$sent = sv_row('offert-laddbox-jarfalla');
sv(sv_table('notis_sends'), 'skickningen skapar notis_sends');
sv(!sv_table('notis_history'), 'skickningen skapar inte historikcachen');
sv(($sent['last_used'] ?? '') === '2026-10-28 09:00', 'sidan läser den sparade tiden, fick ' . ($sent['last_used'] ?? ''));
sv((int) $GLOBALS['_JEAB_NOTIS_HEAVY'] === 0, 'skickningen skannar inte mejlboxen');

sv_light(false);
sv(!jeab_notiser_view_is_light(), 'tick är inte en sidvisning');
jeab_ensure_inbox_sent($pdo, 'sqlite');
$pdo->prepare('INSERT INTO inbox_sent (created_at, thread_id, kind, outcome) VALUES (?, ?, ?, ?)')->execute(['2026-10-01 10:00:00', 't-okand', 'offert', 'sent']);
$pdo->prepare('INSERT INTO inbox_sent (created_at, thread_id, kind, outcome) VALUES (?, ?, ?, ?)')->execute(['2026-10-02 11:00:00', 't-kopt', 'offert', 'sent']);
jeab_notiser_history_refresh();
sv(sv_table('notis_history'), 'tick skapar historikcachen');
sv((int) $GLOBALS['_JEAB_NOTIS_HEAVY'] >= 1, 'tick läser historiken');
$heavy = (int) $GLOBALS['_JEAB_NOTIS_HEAVY'];

sv_light(true);
$GLOBALS['_JEAB_NOTIS_HEAVY'] = 0;
$ladd = sv_row('offert-laddbox-jarfalla');
$a = sv_row('offert-sol-jarfalla-kopt');
$b = sv_row('offert-sol-jarfalla-eget');
sv(($ladd['last_used'] ?? '') === '2026-10-28 09:00', 'senare skickning vinner över historiken, fick ' . ($ladd['last_used'] ?? ''));
sv(($a['last_used'] ?? '') === '2026-10-02 11:00', 'mall A får den kända tiden, fick ' . ($a['last_used'] ?? ''));
sv(($b['last_used'] ?? '') === '', 'mall B får inte den okända tiden, fick ' . ($b['last_used'] ?? ''));
sv((int) $GLOBALS['_JEAB_NOTIS_HEAVY'] === 0, 'sidan efter tick skannar inte, heavy var ' . $heavy);
sv(jeab_notiser_history_refresh() === null && (int) $GLOBALS['_JEAB_NOTIS_HEAVY'] === 0, 'sidan fyller inte cachen');

echo "utskick-sidvisning: ok\n";
