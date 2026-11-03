<?php

declare(strict_types=1);

/**
 * Svar och stäng på en delad PDO.
 * Skick som lyckas men där stängningen faller får inte skickas igen.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-reply-close-' . bin2hex(random_bytes(4));
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
require dirname(__DIR__) . '/src/bootstrap.php';

$fail = 0;
function rc(bool $ok, string $message): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $message . "\n";
    if (!$ok) {
        $fail++;
    }
}

rc(jeab_inkorg_sql_set_storage('sql') && jeab_inkorg_storage_mode() === 'sql', 'sql-läge');
$GLOBALS['_JEAB_INKORG_LLM'] = static fn(): string => throw new RuntimeException('Modellen svarade inte.');
$sent = 0;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use (&$sent) {
    if ($method === 'POST' && (str_ends_with($url, '/sendMail') || str_ends_with($url, '/reply'))) {
        $sent++;

        return ['code' => 202, 'body' => []];
    }

    return ['code' => 500, 'body' => []];
};
jeab_graph_secret_save(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']);

$ing = jeab_inkorg_ingest([
    'message_id' => '<reply-close@example.test>',
    'from' => 'kund.close@example.test',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Hej',
    'body_text' => 'Ett kort hej.',
    'source' => 'graph-jarfalla',
    'graph_id' => 'G1',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'received_at' => '2020-01-02T10:00:00+01:00',
    'test' => true,
]);
rc(!empty($ing['ok']), 'mejlet finns');
$tid = (string) ($ing['thread_id'] ?? '');
$pdo = jeab_inkorg_store_pdo();
$app = jeab_store()->pdo();
rc($pdo instanceof PDO && $app === $pdo, 'inkorg och appen delar PDO');

$store = jeab_inkorg_pdo_store();
rc($store instanceof JeabPdoInkorgStore, 'inkorg-store');
if ($store instanceof JeabPdoInkorgStore) {
    jeab_inkorg_sql_refresh_thread($store, $tid);
}

$throws = 0;
$GLOBALS['_JEAB_INKORG_AFTER_SEND'] = static function () use (&$throws): void {
    $throws++;
    if ($throws === 1) {
        throw new RuntimeException('There is no active transaction');
    }
};

$first = null;
try {
    jeab_inkorg_send_in_thread($tid, ['body' => 'Vi återkommer imorgon.', 'send_key' => 'aa11'], 'Test');
} catch (Throwable $e) {
    $first = $e;
}
rc($first instanceof Throwable && $sent === 1, 'första försöket skickar och faller på stängningen, fick ' . $sent);
rc((string) (jeab_inkorg_thread_meta($tid)['status'] ?? '') !== 'closed', 'ärendet är inte stängt när skrivningen faller');
rc(jeab_inkorg_send_seen($tid, jeab_inkorg_send_dedupe($tid, ['send_key' => 'aa11'], 'Vi återkommer imorgon.', 'kund.close@example.test')), 'dedupe-nyckeln är sparad före stängningen');

unset($GLOBALS['_JEAB_INKORG_AFTER_SEND']);
$second = jeab_inkorg_send_in_thread($tid, ['body' => 'Vi återkommer imorgon.', 'send_key' => 'aa11'], 'Test');
rc($sent === 1 && ($second['state'] ?? '') === 'sent', 'nytt försök skickar inte igen, fick ' . $sent);
rc((string) (jeab_inkorg_thread_meta($tid)['status'] ?? '') === 'closed', 'andra försöket stänger ärendet');
rc(!$pdo->inTransaction() && jeab_pdo_tx_depth($pdo) === 0, 'transaktionen är stängd efter svar och stäng');

$open = jeab_inkorg_ingest([
    'message_id' => '<reply-open@example.test>',
    'from' => 'kund.open@example.test',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Öppen',
    'body_text' => 'Låt det vara öppet.',
    'source' => 'graph-jarfalla',
    'graph_id' => 'G2',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'received_at' => '2020-01-03T10:00:00+01:00',
    'test' => true,
]);
$openTid = (string) ($open['thread_id'] ?? '');
$before = $sent;
jeab_inkorg_send_in_thread($openTid, ['body' => 'Svar utan att stänga.', 'status_after' => 'open', 'send_key' => 'bb22'], 'Test');
rc($sent === $before + 1 && (string) (jeab_inkorg_thread_meta($openTid)['status'] ?? '') === 'open', 'svar utan stäng lämnar ärendet öppet');
$_POST = ['thread' => $openTid, 'status' => 'closed', 'status_ui' => '1', 'status_force' => '1', 'assignee' => ''];
$flash = jeab_inkorg_action('inkorg.thread');
rc($flash !== '' && (string) (jeab_inkorg_thread_meta($openTid)['status'] ?? '') === 'closed', 'Stäng efter svar sparar closed');
rc($sent === $before + 1, 'Stäng skickar inget mejl');

$box = new JeabPdoInboxContactStore($pdo);
$nested = $store instanceof JeabPdoInkorgStore ? $store->updateThread($openTid, static function (array $t) use ($box, $openTid): array {
    jeab_lead_sql();
    $box->locked(static fn(?array $doc): array => [true, null]);
    if (function_exists('jeab_inkorg_side_quote_save')) {
        jeab_inkorg_side_quote_save($openTid, 'citat');
    }
    $t['status'] = 'closed';
    $t['updated_at'] = date('c');

    return $t;
}) : [];
rc(($nested['status'] ?? '') === 'closed' && !$pdo->inTransaction(), 'nästlat kontakt- och sidolager inne i stängningen committar');

$_GET = ['p' => 'inkorg'];
$_POST = ['thread' => $openTid, 'action' => 'inkorg.thread'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$prepared = jeab_inkorg_fast_prepare(false, 'thread');
rc((string) ($prepared['open']['id'] ?? '') === $openTid, 'fel vid stängning lämnar kvar det valda ärendet');

exit($fail > 0 ? 1 : 0);
