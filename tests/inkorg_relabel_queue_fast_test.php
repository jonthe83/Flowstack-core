<?php

declare(strict_types=1);

/**
 * dry=0 på en namngiven tråd köar utan leadhantering, stängning eller granskning.
 * inbox.thread_send_log är bara läsning och visar ingen adress.
 * Påhittade namn. Inget skickas.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-relabel-fast-' . bin2hex(random_bytes(4));
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

function rf(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
    echo "OK  {$message}\n";
}

$src = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_auto.php');
$queueAt = strpos($src, 'function jeab_inkorg_relabel_offer_queue');
$enqueueAt = strpos($src, 'function jeab_inkorg_relabel_offer_enqueue');
$queueFn = $queueAt !== false && $enqueueAt !== false ? substr($src, $queueAt, $enqueueAt - $queueAt) : '';
rf($queueFn !== '' && !str_contains($queueFn, 'jeab_lead_process') && !str_contains($queueFn, 'jeab_inkorg_relabel_offer_apply'), 'kön anropar inte leadhantering');
$forgetAt = strpos($src, 'function jeab_inbox_queue_public_meta');
$enqueueFn = $enqueueAt !== false && $forgetAt !== false ? substr($src, $enqueueAt, $forgetAt - $enqueueAt) : '';
$rememberAt = strpos($enqueueFn, 'jeab_inkorg_relabel_offer_idem_remember');
$queueCallAt = strpos($enqueueFn, 'jeab_inbox_queue_quote');
$confirmAt = strpos($enqueueFn, "unset(\$GLOBALS['_JEAB_QUOTE_QUEUE_MEM'])");
$reloadAt = $confirmAt === false ? false : strpos($enqueueFn, 'jeab_inbox_quote_queue_load', $confirmAt);
rf($enqueueFn !== ''
    && $rememberAt !== false
    && $queueCallAt !== false
    && $confirmAt !== false
    && $reloadAt !== false
    && $queueCallAt < $confirmAt
    && $confirmAt < $reloadAt
    && $reloadAt < $rememberAt
    && !str_contains($enqueueFn, 'jeab_lead_process')
    && !str_contains($enqueueFn, 'jeab_no_email_maybe')
    && !str_contains($enqueueFn, 'jeab_auto_send_quote')
    && !str_contains($enqueueFn, 'jeab_inkorg_close_open_for_reminder')
    && !str_contains($enqueueFn, 'jeab_address_extract')
    && !str_contains($enqueueFn, 'jeab_auto_mark_needs_review'), 'idem skrivs först när raden är läst tillbaka');
rf(str_contains(implode("\n", jeab_auto_rule_lines()), 'inbox.thread_send_log'), 'regeln nämner sändloggen');
rf(str_contains(implode("\n", jeab_auto_rule_lines()), 'Misslyckas sparningen skrivs ingen nyckel'), 'regeln säger att en misslyckad sparning inte skriver nyckel');

$GLOBALS['_SENT'] = [];
$GLOBALS['_SMS'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $mail): array {
    $GLOBALS['_SENT'][] = $mail;

    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    $GLOBALS['_SENT'][] = $opts;

    return ['ok' => true, 'path' => 'stub', 'id' => 'm1', 'from' => 'saljare3@jarfalla.example.test'];
};
$GLOBALS['JEAB_SMS_SEND'] = static function (): array {
    $GLOBALS['_SMS'][] = 1;

    return ['ok' => true, 'queued' => 1];
};

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);

$in = jeab_inkorg_ingest([
    'message_id' => 'lead-fast@relabel.test',
    'from' => 'info@wix-forms.com',
    'from_name' => 'Formulär',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Offert',
    'body_text' => "Namn: Fejk Kent\nE-post: fejk.kent@gmail.com\nTelefon: 0701112299\nAdress: Fejkgatan 3\nProdukt: Batteri\n",
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'thread_key' => 'lead-fast',
    'received_at' => date('c'),
    'preset_labels' => [
        'type' => 'Information', 'action' => 'arkiv', 'priority' => 3, 'confidence' => 90,
        'intent' => 'info', 'offer' => '', 'product' => '', 'question' => '',
    ],
]);
rf(!empty($in['ok']), 'ärendet tas in');
$tid = (string) $in['thread_id'];
$mid = (string) $in['id'];
jeab_inkorg_thread_set($tid, 'open', ['batteri'], false);
jeab_inkorg_update($mid, static function (array $m): array {
    $labels = (array) ($m['labels'] ?? []);
    $labels['type'] = 'Ny lead';
    $labels['offer'] = 'batteri';
    $labels['product'] = 'batteri';
    $labels['intent'] = 'kop';
    $labels['source'] = 'manual';
    $labels['confidence'] = 100;
    $labels['needs_review'] = false;
    $m['labels'] = $labels;

    return $m;
});
$made = jeab_create_reminder(jeab_store(), 'Fejk Kent – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Fejk Kent',
    'email' => 'fejk.kent@gmail.com',
    'phone' => '0701112299',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'Finns sedan tidigare.',
]);
rf(is_array($made) && (int) ($made['id'] ?? 0) > 0, 'påminnelsen finns');
jeab_inkorg_thread_set($tid, 'open', null, false);
jeab_inkorg_update($mid, static function (array $m): array {
    $labels = (array) ($m['labels'] ?? []);
    $labels['needs_review'] = false;
    $m['labels'] = $labels;

    return $m;
});
$reminders = count(jeab_list_reminders(jeab_store(), true));
$live = jeab_inkorg_hook(['action' => 'inbox.relabel_offer_queue', 'thread_id' => $tid, 'dry' => 0]);
$meta = jeab_inkorg_thread_meta($tid);
$msg = jeab_inkorg_get($mid);
$labels = is_array($msg) ? (array) ($msg['labels'] ?? []) : [];
rf((int) ($live['queued'] ?? 0) === 1 && (int) ($live['sent'] ?? 1) === 0 && ($live['would_send'] ?? true) === false, 'dry 0 köar utan att skicka');
rf((string) ($meta['status'] ?? '') === 'open', 'ärendet förblir öppet');
rf(empty($labels['needs_review']), 'manuell etikett blir inte granskning');
rf(count(jeab_list_reminders(jeab_store(), true)) === $reminders, 'ingen ny påminnelse i anropet');
rf(jeab_inkorg_relabel_offer_idem_seen($tid, 'batteri'), 'idempotensnyckeln finns');
rf($GLOBALS['_SENT'] === [] && $GLOBALS['_SMS'] === [], 'varken mejl eller SMS gick');
$again = jeab_inkorg_hook(['action' => 'inbox.relabel_offer_queue', 'thread_id' => $tid, 'dry' => 0]);
rf((int) ($again['queued'] ?? 1) === 0 && ($again['reason'] ?? '') === 'redan köad', 'samma tråd köas inte igen');

$secret = 'fejk.hemlig@gmail.com';
jeab_inbox_sent_record($tid, 'auto', 'system', 'offert', ['outcome' => 'sent', 'channel' => 'email']);
$pdo = jeab_store()->pdo();
jeab_send_guard_ensure($pdo);
$job = base64_encode(serialize([
    'args' => ['opts' => [
        'to' => $secret,
        'thread_id' => $tid,
        'text' => 'Hej ' . $secret,
    ]],
]));
$other = base64_encode(serialize([
    'args' => ['opts' => [
        'to' => $secret,
        'thread_id' => 'annan-trad',
        'text' => 'Hej ' . $secret,
    ]],
]));
$stamp = date('Y-m-d H:i:s');
$insert = $pdo->prepare('INSERT INTO send_queue (channel, template, rcpt_hash, body_hash, not_before, created_at, job, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$insert->execute(['email', 'offert-batteri-jarfalla', hash('sha256', 'a'), hash('sha256', 'b'), $stamp, $stamp, $job, 'waiting']);
$insert->execute(['email', 'offert-sol-jarfalla', hash('sha256', 'c'), hash('sha256', 'd'), $stamp, $stamp, $other, 'waiting']);
$before = count(jeab_list_reminders(jeab_store(), true));
$statusBefore = (string) (jeab_inkorg_thread_meta($tid)['status'] ?? '');
$log = jeab_inkorg_hook(['action' => 'inbox.thread_send_log', 'thread_id' => $tid]);
$sources = [];
foreach ((array) ($log['rows'] ?? []) as $row) {
    if (is_array($row)) {
        $sources[] = (string) ($row['source'] ?? '');
    }
}
sort($sources);
rf(($log['ok'] ?? false) === true && ($log['read_only'] ?? false) === true && (int) ($log['sent'] ?? 1) === 0, 'sändloggen är läsning');
rf($sources === ['queue', 'send_guard', 'sent_log'], 'loggen har kö, spärr och sent_log');
$blob = json_encode($log, JSON_UNESCAPED_UNICODE);
rf(is_string($blob) && !str_contains($blob, '@') && !str_contains($blob, $secret) && !str_contains($blob, 'annan-trad'), 'loggen har ingen adress och ingen annan tråd');
foreach ((array) ($log['rows'] ?? []) as $row) {
    rf(is_array($row) && trim((string) ($row['time'] ?? '')) !== '' && trim((string) ($row['template'] ?? '')) !== '' && trim((string) ($row['status'] ?? '')) !== '', 'varje rad har tid, mall och status');
}
rf(count(jeab_list_reminders(jeab_store(), true)) === $before && (string) (jeab_inkorg_thread_meta($tid)['status'] ?? '') === $statusBefore, 'läsningen skriver inget');
$list = jeab_inkorg_hook(['action' => 'inbox.thread_send_log', 'threads' => [$tid]]);
rf(($list['error'] ?? '') === 'one_thread' && ($list['rows'] ?? null) === [], 'en lista läses inte');

function rf_open_lead(string $slug): string
{
    $in = jeab_inkorg_ingest([
        'message_id' => $slug . '@relabel.test',
        'from' => 'info@wix-forms.com',
        'from_name' => 'Formulär',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Offert',
        'body_text' => "Namn: Fejk {$slug}\nE-post: fejk.{$slug}@gmail.com\nTelefon: 0701112201\nAdress: Fejkgatan 3\nProdukt: Batteri\n",
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'thread_key' => $slug,
        'received_at' => date('c'),
        'preset_labels' => [
            'type' => 'Information', 'action' => 'arkiv', 'priority' => 3, 'confidence' => 90,
            'intent' => 'info', 'offer' => '', 'product' => '', 'question' => '',
        ],
    ]);
    rf(!empty($in['ok']), $slug . ' tas in');
    $lead = (string) $in['thread_id'];
    $mid = (string) $in['id'];
    jeab_inkorg_thread_set($lead, 'open', ['batteri'], false);
    jeab_inkorg_update($mid, static function (array $m): array {
        $labels = (array) ($m['labels'] ?? []);
        $labels['type'] = 'Ny lead';
        $labels['offer'] = 'batteri';
        $labels['product'] = 'batteri';
        $labels['intent'] = 'kop';
        $labels['source'] = 'manual';
        $labels['confidence'] = 100;
        $labels['needs_review'] = false;
        $m['labels'] = $labels;

        return $m;
    });

    return $lead;
}

function rf_queue_has(string $lead): bool
{
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
    $all = jeab_inbox_quote_queue_load();

    return isset($all[$lead]) && is_array($all[$lead]);
}

function rf_failed_save(string $lead, string $label): void
{
    $live = jeab_inkorg_hook(['action' => 'inbox.relabel_offer_queue', 'thread_id' => $lead, 'dry' => 0]);
    rf(($live['ok'] ?? true) === false && ($live['error'] ?? '') === 'köade inte' && (int) ($live['queued'] ?? 1) === 0, $label . ' säger att kön inte sparades');
    rf(!jeab_inkorg_relabel_offer_idem_seen($lead, 'batteri'), $label . ' skriver ingen nyckel');
    rf(!rf_queue_has($lead), $label . ' lämnar ingen rad');
}

$throwLead = rf_open_lead('kastar');
$GLOBALS['_JEAB_DATA_TYPE_LISTS'] = ['sql' => ['quote_queue'], 'json' => []];
$GLOBALS['_JEAB_DATA_MAINTENANCE'] = true;
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
rf(jeab_cutover_sql('quote_queue') === true, 'quote_queue skrivs via SQL när sparningen ska kasta');
rf_failed_save($throwLead, 'ett kast');
$GLOBALS['_JEAB_DATA_MAINTENANCE'] = false;
unset($GLOBALS['_JEAB_DATA_TYPE_LISTS'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$throwAgain = jeab_inkorg_hook(['action' => 'inbox.relabel_offer_queue', 'thread_id' => $throwLead, 'dry' => 0]);
rf((int) ($throwAgain['queued'] ?? 0) === 1 && ($throwAgain['ok'] ?? false) === true, 'nästa dry 0 köar efter kastet');
rf(jeab_inkorg_relabel_offer_idem_seen($throwLead, 'batteri') && rf_queue_has($throwLead), 'nyckel och rad finns efter kastet');

$silentLead = rf_open_lead('tyst');
$GLOBALS['_JEAB_QUOTE_QUEUE_FORCE'] = 'json';
$queuePath = $tmp . '/quote-queue.json';
$queueBak = $queuePath . '.bak';
if (is_file($queuePath)) {
    rename($queuePath, $queueBak);
}
rf(!is_file($queuePath) && mkdir($queuePath, 0775), 'kön pekar på en katalog');
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
rf_failed_save($silentLead, 'en tyst sparning');
rmdir($queuePath);
if (is_file($queueBak)) {
    rename($queueBak, $queuePath);
}
unset($GLOBALS['_JEAB_QUOTE_QUEUE_FORCE'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$silentAgain = jeab_inkorg_hook(['action' => 'inbox.relabel_offer_queue', 'thread_id' => $silentLead, 'dry' => 0]);
rf((int) ($silentAgain['queued'] ?? 0) === 1 && ($silentAgain['ok'] ?? false) === true, 'nästa dry 0 köar efter den tysta sparningen');
rf(jeab_inkorg_relabel_offer_idem_seen($silentLead, 'batteri') && rf_queue_has($silentLead), 'nyckel och rad finns efter den tysta sparningen');

echo "OK  snabb relabel-kö\n";
