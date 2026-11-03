<?php

declare(strict_types=1);

/**
 * Webbens inbox.send_quote med dry=0 lägger tråden i offertkön.
 * Sändlåset kan vara upptaget: inget mejl går, och sent-loggen får ingen rad.
 * Påhittade adresser. Inget skickas mot nätet.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-send-quote-web-queue-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

function wq(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$srcAuto = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_auto.php');
$drainAt = strpos($srcAuto, 'function jeab_inbox_queue_quote_drain');
$drainEnd = strpos($srcAuto, 'function jeab_auto_quote_sent_source_label');
$drainFn = $drainAt !== false && $drainEnd !== false ? substr($srcAuto, $drainAt, $drainEnd - $drainAt) : '';
wq($drainFn !== '' && str_contains($drainFn, 'jeab_auto_send_quote(jeab_quote_outbox_send_input((string) $tid, $product))'), 'cron anropar fortfarande send_quote');
wq(!str_contains($drainFn, 'confirm') && !str_contains($drainFn, 'SAM') && !str_contains($drainFn, 'offer'), 'tömningen har varken confirm, SAM eller offer');
wq(!str_contains($drainFn, 'jeab_auto_send_quote_should_queue') && !str_contains($drainFn, 'jeab_inbox_queue_quote('), 'tömningen köar inte om');

$sendCalls = 0;
$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a) use (&$sendCalls): array {
    $sendCalls++;
    $GLOBALS['_SENT'][] = $a;

    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$sendCalls): array {
    $sendCalls++;
    $GLOBALS['_SENT'][] = $opts;

    return ['ok' => true, 'path' => 'stub', 'id' => 'm1', 'from' => 'saljare3@jarfalla.example.test'];
};
$graphCalls = 0;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function () use (&$graphCalls): array {
    $graphCalls++;

    return ['code' => 200, 'body' => ['value' => [], 'id' => 'sent-folder']];
};
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode("%PDF-1.4 web-queue\n")]);

$ingest = static function (string $key, string $email, string $name): string {
    $in = jeab_inkorg_ingest([
        'message_id' => $key . '@web-queue.test',
        'from' => 'info@wix-forms.com',
        'from_name' => 'Formulär',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Offert',
        'body_text' => "Namn: {$name}\nE-post: {$email}\nTelefon: 070-000 00 41\nAdress: Fejkgatan 11, 176 70 Järfälla\nProdukt: Batteri\n",
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'thread_key' => $key,
        'preset_labels' => [
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
            'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => '',
        ],
    ]);
    wq(!empty($in['ok']), 'ärendet ' . $key . ' tas in');

    return (string) $in['thread_id'];
};

$sentFor = static function (string $tid): int {
    $n = 0;
    foreach (jeab_inbox_sent_load() as $row) {
        if (is_array($row) && (string) ($row['thread_id'] ?? '') === $tid) {
            $n++;
        }
    }

    return $n;
};

$email = 'fejk.las@gmail.com';
$tid = $ingest('web-lock', $email, 'Fejk Las');
$remId = 0;
$rem = function_exists('jeab_lead_find_reminder') ? jeab_lead_find_reminder($tid, $email) : null;
if (is_array($rem)) {
    $remId = (int) ($rem['id'] ?? 0);
}
$keys = jeab_offer_idem_keys($tid, $email, 'batteri', $remId);
$pdo = jeab_send_once_pdo();
wq($pdo instanceof PDO && $keys !== [], 'sändlåset kan reserveras');
jeab_send_once_ready($pdo);
$driver = jeab_send_once_driver($pdo);
$claim = 'bbbbbbbbbbbbbbbb';
foreach ($keys as $key) {
    jeab_send_once_upsert_claim($pdo, $driver, 'offert', $key, $claim, time() + 600, time());
}
wq(jeab_send_once_pending($pdo, $keys) && jeab_send_once_peek('offert', $keys, 600) === null, 'sändlåset är upptaget utan färdigt resultat');

$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
unset($GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_AUTO_QUOTE_BUDGET_START']);
wq(jeab_auto_llm_is_web_request(), 'anropet räknas som webb');
$beforeSent = $sentFor($tid);
$first = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $tid, 'dry' => 0]);
$queue = jeab_inbox_quote_queue_load();
$queuePath = jeab_data_dir() . '/quote-queue.json';
$queueSnap = static function () use ($queuePath): string {
    if (is_file($queuePath)) {
        return (string) file_get_contents($queuePath);
    }
    $encoded = json_encode(jeab_inbox_quote_queue_load(), JSON_UNESCAPED_UNICODE);

    return is_string($encoded) ? $encoded : '';
};
$queueRaw = $queueSnap();
wq(($first['ok'] ?? false) === true && ($first['status'] ?? '') === 'köad' && (int) ($first['sent'] ?? 1) === 0 && (int) ($first['already'] ?? 1) === 0, 'upptaget sändlås ger köad, fick ' . json_encode($first, JSON_UNESCAPED_UNICODE));
wq($sendCalls === 0 && count($GLOBALS['_SENT']) === 0 && $graphCalls === 0, 'inget utskick och ingen Graph');
wq($sentFor($tid) === $beforeSent && $beforeSent === 0, 'ingen rad i skickat-loggen');
wq(isset($queue[$tid]) && count($queue) === 1 && !str_contains($queueRaw, '@'), 'en köad rad utan adress');
$queueHash = hash('sha256', $queueRaw);
$queuedAt = (int) ($queue[$tid]['at'] ?? 0);

$second = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $tid, 'dry' => '0']);
$again = jeab_inbox_quote_queue_load();
wq(($second['status'] ?? '') === 'redan köad' && (int) ($second['already'] ?? 0) === 1 && (int) ($second['sent'] ?? 1) === 0, 'samma tråd blir redan köad');
wq(count($again) === 1 && (int) ($again[$tid]['at'] ?? 0) === $queuedAt && hash('sha256', $queueSnap()) === $queueHash, 'ingen ny köad rad');
wq($sendCalls === 0 && $sentFor($tid) === 0, 'andra anropet skickar inte och loggar inte');

$_POST = ['action' => 'inkorg.send_quote', 'thread' => $tid, 'dry' => '0'];
$page = jeab_inkorg_action('inkorg.send_quote');
wq($page === 'Offerten ligger redan i kön.' && jeab_flash_text($page) === $page, 'sidan säger att offerten redan ligger i kön, fick ' . $page);
wq(count(jeab_inbox_quote_queue_load()) === 1 && $sendCalls === 0, 'sidan skapar ingen ny rad och skickar inte');

$doneEmail = 'fejk.redan@gmail.com';
$done = $ingest('web-done', $doneEmail, 'Fejk Redan');
jeab_inkorg_thread_write($done, static function (array $t): array {
    $t['quote_sent'] = ['source' => 'anteckning', 'date' => '2026-10-01 12:00', 'at' => '2026-10-01T10:00:00+00:00', 'product' => 'batteri'];

    return $t;
});
$doneRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $done, 'dry' => 0]);
$after = jeab_inbox_quote_queue_load();
wq(!empty($doneRes['already_sent']) && ($doneRes['why'] ?? '') === 'redan skickad' && (int) ($doneRes['sent'] ?? 1) === 0, 'redan skickad offert köas inte, fick ' . json_encode($doneRes, JSON_UNESCAPED_UNICODE));
wq(!isset($after[$done]) && isset($after[$tid]) && count($after) === 1, 'kön har bara den första tråden');
wq($sendCalls === 0 && $graphCalls === 0 && $sentFor($done) === 0 && $sentFor($tid) === 0, 'redan skickad lämnar utskick och sent-logg orörda');

$pageEmail = 'fejk.sida@gmail.com';
$pageTid = $ingest('web-page', $pageEmail, 'Fejk Sida');
$pageRem = function_exists('jeab_lead_find_reminder') ? jeab_lead_find_reminder($pageTid, $pageEmail) : null;
$pageRemId = is_array($pageRem) ? (int) ($pageRem['id'] ?? 0) : 0;
$pageKeys = jeab_offer_idem_keys($pageTid, $pageEmail, 'batteri', $pageRemId);
foreach ($pageKeys as $key) {
    jeab_send_once_upsert_claim($pdo, $driver, 'offert', $key, $claim, time() + 600, time());
}
wq(jeab_send_once_pending($pdo, $pageKeys) && jeab_send_once_peek('offert', $pageKeys, 600) === null, 'sidans tråd har upptaget sändlås');
$_POST = ['action' => 'inkorg.send_quote', 'thread' => $pageTid, 'dry' => '0'];
$pageFirst = jeab_inkorg_action('inkorg.send_quote');
wq($pageFirst === 'Offerten är köad.' && jeab_flash_text($pageFirst) === $pageFirst, 'sidan svarar att offerten är köad, fick ' . $pageFirst);
wq(isset(jeab_inbox_quote_queue_load()[$pageTid]) && !isset(jeab_inbox_quote_queue_load()[$done]) && $sendCalls === 0 && $sentFor($pageTid) === 0, 'sidan köar utan utskick och utan sent-rad');

echo "inkorg send quote web queue ok\n";
