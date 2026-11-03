<?php

declare(strict_types=1);

/**
 * inkorg.learn_stats är bara antal.
 * Manuell Ny lead med produkt lär Grok, skapar påminnelse hos rätt säljare
 * och köar en offert. Avstängt utskick ger bara en anteckning.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-learn-stats-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ls_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ls_ok(bool $ok, string $message): void
{
    if (!$ok) {
        ls_fail($message);
    }
    echo "OK  {$message}\n";
}

$secretMail = 'hemlig.learn@example.test';
$secretPhone = '0701998877';
$secretName = 'Fejk Hemlig';

$GLOBALS['_SENT'] = [];
$GLOBALS['_SMS'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;

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
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (): array {
    return ['code' => 200, 'body' => ['value' => []]];
};
$GLOBALS['_JEAB_INKORG_LLM'] = static function (): string {
    throw new RuntimeException('Modellen svarade inte.');
};

ls_ok(jeab_inkorg_sql_set_storage('sql') && jeab_inkorg_storage_mode() === 'sql', 'sql-läge');

$quoteOn = [
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
];
jeab_auto_settings_save($quoteOn);

$recent = date('c', time() - 86400);
$oldAt = date('c', time() - 40 * 86400);
$since = date('Y-m-d', time() - 20 * 86400);

$ingest = static function (string $key, string $body, array $labels, string $company, string $when) use ($secretName): array {
    $malmo = $company === 'malmo';
    $in = jeab_inkorg_ingest([
        'message_id' => $key . '@learn.test',
        'from' => 'info@wix-forms.com',
        'from_name' => 'Formulär',
        'to' => $malmo ? 'kontakt@malmo.example.test' : 'kontakt@jarfalla.example.test',
        'subject' => 'Offert',
        'body_text' => $body,
        'source' => $malmo ? 'graph-malmo' : 'graph-jarfalla',
        'graph_mailbox' => $malmo ? 'kontakt@malmo.example.test' : 'kontakt@jarfalla.example.test',
        'thread_key' => $key,
        'received_at' => $when,
        'preset_labels' => $labels,
    ]);
    ls_ok(!empty($in['ok']), 'ärendet ' . $key . ' tas in');

    return ['id' => (string) $in['id'], 'thread' => (string) $in['thread_id']];
};

$body = static function (string $name, string $email, string $phone, string $product): string {
    return "Namn: {$name}\nE-post: {$email}\nTelefon: {$phone}\nAdress: Fejkgatan 3, 176 70 Järfälla\nProdukt: {$product}\n";
};

$queueCount = static function (): int {
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);

    return count(jeab_inbox_quote_queue_load());
};

$reminderOn = static function (string $tid): array {
    $hit = [];
    foreach (jeab_list_reminders(jeab_store(), true) as $rem) {
        if (!is_array($rem) || (string) ($rem['thread_id'] ?? '') !== $tid) {
            continue;
        }
        $hit[] = $rem;
    }

    return $hit;
};

$sellerOf = static function (array $rem): string {
    $names = [];
    $one = trim((string) ($rem['assignee'] ?? ''));
    if ($one !== '') {
        $names[] = $one;
    }
    foreach ((array) ($rem['assignees'] ?? []) as $name) {
        $name = trim((string) $name);
        if ($name !== '') {
            $names[] = $name;
        }
    }
    $who = trim((string) ($rem['who'] ?? ''));
    if ($who !== '') {
        $names[] = $who;
    }

    return implode(',', $names);
};

$learnCount = static function (): int {
    $pdo = jeab_inkorg_learn_pdo();
    if (!$pdo instanceof PDO) {
        return -1;
    }
    jeab_inkorg_learn_ensure($pdo);

    return (int) $pdo->query('SELECT COUNT(*) FROM inkorg_learn_queue')->fetchColumn();
};

$labels = static function (string $type, int $confidence, string $source, bool $review = false): array {
    return [
        'type' => $type,
        'action' => 'arkiv',
        'priority' => 3,
        'confidence' => $confidence,
        'source' => $source,
        'needs_review' => $review,
        'intent' => 'info',
        'offer' => '',
        'product' => '',
        'reason' => 'test',
    ];
};

$beforeLearn = $learnCount();
ls_ok($beforeLearn === 0, 'inlärningskön är tom från start');

$one = $ingest('thr-batteri', $body($secretName, 'fejk.batteri@gmail.com', '0701112233', 'Batteri'), $labels('Information', 90, 'llm'), 'jarfalla', $recent);
jeab_inkorg_thread_set($one['thread'], 'open', ['batteri'], false);
$beforeReminders = count(jeab_list_reminders(jeab_store(), true));
jeab_inkorg_relabel($one['id'], ['type' => 'Ny lead'], 'personal');
ls_ok($learnCount() === 1, 'relabel till Ny lead lägger inlärningen i kön');
$queue = jeab_inbox_quote_queue_load();
ls_ok($queueCount() === 1 && isset($queue[$one['thread']]), 'relabel köar en offert');
ls_ok((string) ($queue[$one['thread']]['product'] ?? '') === 'batteri', 'köns produkt är batteri');
ls_ok((string) ($queue[$one['thread']]['idem'] ?? '') === $one['thread'] . '|batteri', 'idempotensnyckeln är tråd och produkt');
$rems = $reminderOn($one['thread']);
ls_ok(count($rems) === 1 && count(jeab_list_reminders(jeab_store(), true)) === $beforeReminders + 1, 'leadflödet skapar en påminnelse');
ls_ok(str_contains($sellerOf($rems[0]), 'Roberto'), 'batteri går till Roberto, fick ' . $sellerOf($rems[0]));
$meta = jeab_inkorg_thread_meta($one['thread']);
ls_ok((string) ($meta['assignee'] ?? '') === 'Roberto', 'ärendet tilldelas Roberto');
ls_ok(count($GLOBALS['_SENT']) === 0 && count($GLOBALS['_SMS']) === 0, 'relabel skickar inget mejl och inget SMS');

jeab_inkorg_relabel($one['id'], ['type' => 'Ny lead'], 'personal');
ls_ok($queueCount() === 1 && count($reminderOn($one['thread'])) === 1 && $learnCount() === 1, 'samma relabel ger ingen andra offert, påminnelse eller inlärning');

$quoteOff = $quoteOn;
$quoteOff['auto_quote_enabled'] = 0;
jeab_auto_settings_save($quoteOff);
$held = $ingest('thr-av', $body('Fejk Av', 'fejk.av@gmail.com', '0701112222', 'Batteri'), $labels('Information', 90, 'llm'), 'jarfalla', $recent);
jeab_inkorg_thread_set($held['thread'], 'open', ['batteri'], false);
$heldReminders = count(jeab_list_reminders(jeab_store(), true));
$heldQueue = $queueCount();
jeab_inkorg_relabel($held['id'], ['type' => 'Ny lead'], 'personal');
$heldNotes = '';
foreach ((array) (jeab_inkorg_thread_meta($held['thread'])['drafts'] ?? []) as $draft) {
    if (is_array($draft)) {
        $heldNotes .= (string) ($draft['body'] ?? '');
    }
}
ls_ok($learnCount() === 2, 'avstängt utskick lär ändå');
ls_ok($queueCount() === $heldQueue && count(jeab_list_reminders(jeab_store(), true)) === $heldReminders, 'avstängt utskick köar inte och skapar ingen påminnelse');
ls_ok(str_contains($heldNotes, 'offertutskick är av'), 'avstängt utskick skriver bara en anteckning');
ls_ok(count($GLOBALS['_SENT']) === 0, 'avstängt utskick skickar inget');

jeab_auto_settings_save($quoteOn);
$box = $ingest('thr-ladd', $body('Fejk Ladd', 'fejk.ladd@gmail.com', '0701112244', 'Elbilsladdare'), $labels('Ny lead', 95, 'llm'), 'jarfalla', $recent);
$beforeBox = $learnCount();
jeab_inkorg_thread_set($box['thread'], 'open', ['elbilsladdare'], false);
jeab_inkorg_case_product_labels($box['thread'], ['elbilsladdare']);
$boxQueue = jeab_inbox_quote_queue_load();
ls_ok($learnCount() === $beforeBox + 1, 'manuell produkt lär Grok');
ls_ok($queueCount() === 2 && (string) ($boxQueue[$box['thread']]['product'] ?? '') === 'laddbox' && (string) ($boxQueue[$box['thread']]['idem'] ?? '') === $box['thread'] . '|laddbox', 'produktetikett köar elbilsladdare en gång');
$boxRem = $reminderOn($box['thread']);
ls_ok(count($boxRem) === 1 && str_contains($sellerOf($boxRem[0]), 'Elin'), 'elbilsladdare går till Elin, fick ' . $sellerOf($boxRem[0]));
jeab_inkorg_case_product_labels($box['thread'], ['elbilsladdare']);
ls_ok($queueCount() === 2 && count($reminderOn($box['thread'])) === 1 && $learnCount() === $beforeBox + 1, 'samma produkt köar inte igen');

$info = $labels('Information', 88, 'llm');
$skrap = $labels('Skräp', 91, 'llm');
$ingest('thr-info', $body('Fejk Info', 'fejk.info@gmail.com', '0701112255', 'Sol'), $info, 'malmo', $recent);
$ingest('thr-skrap', "Namn: {$secretName}\nKort reklam.", $skrap, 'jarfalla', $recent);
$later = date('c', time() - 3600);
$ingest('thr-skrap', 'Orderbekräftelse utan kund.', $labels('Order', 93, 'llm'), 'jarfalla', $later);
$ingest('thr-lag', $body('Fejk Lag', 'fejk.lag@gmail.com', '0701112266', 'Batteri'), $labels('Information', 40, 'llm'), 'jarfalla', $recent);
$ingest('thr-granska', $body('Fejk Granska', 'fejk.granska@gmail.com', '0701112277', 'Batteri'), $labels('Support', 95, 'llm', true), 'jarfalla', $recent);
$ingest('thr-gammal', $body('Fejk Gammal', 'fejk.gammal@gmail.com', '0701112288', 'Batteri'), $skrap, 'jarfalla', $oldAt);
$manualSpam = $ingest('thr-mskr', 'Reklam till Malmö.', $labels('Skräp', 20, 'llm', true), 'malmo', $recent);
jeab_inkorg_update($manualSpam['id'], static function (array $m): array {
    $labels = (array) ($m['labels'] ?? []);
    $labels['type'] = 'Skräp';
    $labels['source'] = 'manual';
    $labels['confidence'] = 100;
    $labels['needs_review'] = false;
    $m['labels'] = $labels;

    return $m;
});

$sentLead = $ingest('thr-sent', $body('Fejk Sent', 'fejk.sent@gmail.com', '0701112299', 'Batteri'), $labels('Information', 80, 'llm'), 'jarfalla', $recent);
jeab_inkorg_update($sentLead['id'], static function (array $m): array {
    $labels = (array) ($m['labels'] ?? []);
    $labels['type'] = 'Ny lead';
    $labels['source'] = 'manual';
    $labels['confidence'] = 100;
    $labels['needs_review'] = false;
    $m['labels'] = $labels;

    return $m;
});
$store = jeab_store();
ls_ok($store instanceof JeabPdoStore, 'sqlite är öppen');
jeab_ensure_inbox_sent($store->pdo(), 'sqlite');
$store->pdo()->prepare('INSERT INTO inbox_sent (created_at, thread_id, via, actor, kind) VALUES (?, ?, ?, ?, ?)')->execute([
    date('c'), $sentLead['thread'], 'auto', 'system', 'offert',
]);
jeab_create_reminder($store, 'Fejk Sent – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Fejk Sent',
    'email' => 'fejk.sent@gmail.com',
    'phone' => '0701112299',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'fanns i loggen',
    'thread_id' => $sentLead['thread'],
]);

$logOnly = $ingest('thr-log', $body('Fejk Log', 'fejk.log@gmail.com', '0701112200', 'Batteri'), $labels('Ny lead', 80, 'llm'), 'jarfalla', $recent);
jeab_inkorg_update($logOnly['id'], static function (array $m): array {
    $labels = (array) ($m['labels'] ?? []);
    $labels['source'] = 'manual';
    $labels['type'] = 'Ny lead';
    $labels['confidence'] = 100;
    $labels['needs_review'] = false;
    $m['labels'] = $labels;

    return $m;
});
$store->pdo()->prepare('INSERT INTO inbox_sent (created_at, thread_id, via, actor, kind) VALUES (?, ?, ?, ?, ?)')->execute([
    date('c'), $logOnly['thread'], 'manual', 'hook', 'svar',
]);
$store->pdo()->prepare('INSERT INTO inbox_sent (created_at, thread_id, via, actor, kind) VALUES (?, ?, ?, ?, ?)')->execute([
    date('c'), $logOnly['thread'], 'auto', 'system', 'offert',
]);

$remOnly = $ingest('thr-rem', $body('Fejk Rem', 'fejk.rem@gmail.com', '0701112211', 'Batteri'), $labels('Ny lead', 80, 'llm'), 'jarfalla', $recent);
jeab_inkorg_update($remOnly['id'], static function (array $m): array {
    $labels = (array) ($m['labels'] ?? []);
    $labels['source'] = 'manual';
    $labels['type'] = 'Ny lead';
    $labels['confidence'] = 100;
    $labels['needs_review'] = false;
    $m['labels'] = $labels;

    return $m;
});
jeab_create_reminder($store, 'Fejk Rem – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Fejk Rem',
    'email' => 'fejk.rem@gmail.com',
    'phone' => '0701112211',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'utan offert',
    'thread_id' => $remOnly['thread'],
]);

$drained = jeab_inkorg_learn_drain();
ls_ok($drained === 3 && $learnCount() === 0, 'tick sparar de tre exemplen');
$examples = jeab_inkorg_settings()['examples'];
ls_ok(count($examples) === 3, 'tre exempel är sparade');

jeab_inkorg_locked('settings.json', static function (?array $d): array {
    $d = $d ?? [];
    $d['examples'][] = [
        'id' => 'old-ex',
        'from' => 'gammal.learn@example.test',
        'subject' => 'gammalt',
        'snippet' => 'gammalt',
        'label' => 'typ:Skräp',
        'by' => 'personal',
        'at' => '2020-01-02T10:00:00+01:00',
        'message' => 'old-ex',
    ];

    return [null, $d];
});
unset($GLOBALS['_JEAB_INKORG_SETTINGS_MEM'], $GLOBALS['_JEAB_INKORG_SETTINGS_STAMP']);

$pdo = jeab_inkorg_learn_pdo();
ls_ok($pdo instanceof PDO, 'köns databas är öppen');
$pdo->prepare('INSERT INTO inkorg_learn_queue (qid, payload) VALUES (?, ?)')->execute([
    'x-decoy',
    '{"from":"' . $secretMail . '","body":"hemlig text ' . $secretPhone . '"}',
]);

$messagesBefore = (int) $pdo->query('SELECT COUNT(*) FROM inkorg_messages')->fetchColumn();
$remindersBefore = (int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn();
$sentBefore = (int) $pdo->query('SELECT COUNT(*) FROM inbox_sent')->fetchColumn();
$queueBefore = $queueCount();
$settingsBefore = jeab_inkorg_settings_cache_stamp();

$stats = jeab_inkorg_learn_stats(['since' => $since, 'email' => $secretMail]);
$encoded = json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
ls_ok(is_string($encoded), 'svaret går att koda');
ls_ok(($stats['ok'] ?? false) === true && ($stats['read_only'] ?? false) === true && (int) ($stats['sent'] ?? 1) === 0 && ($stats['would_send'] ?? true) === false, 'hooken är läsande');
ls_ok((string) ($stats['since'] ?? '') === $since && (int) ($stats['review_min_confidence'] ?? 0) === jeab_inkorg_review_min_confidence(), 'since och gränsen följer med');
ls_ok((int) ($stats['examples'] ?? 0) === 3, 'exempel från since räknas, det gamla inte');
ls_ok((int) ($stats['learn_queue'] ?? 0) === 1, 'inlärningskön räknas som rader');

$jar = $stats['companies']['jarfalla'] ?? [];
$mal = $stats['companies']['malmo'] ?? [];
ls_ok(is_array($jar) && is_array($mal) && count($stats['companies'] ?? []) === 2, 'bara Järfälla och Malmö');
ls_ok((int) ($jar['types']['Ny lead'] ?? 0) === 6, 'Järfälla har sex Ny lead, fick ' . (int) ($jar['types']['Ny lead'] ?? 0));
ls_ok((int) ($jar['types']['Order'] ?? 0) === 1 && !isset($jar['types']['Skräp']), 'senaste mejlet i tråden räknas, inte det äldre Skräp');
ls_ok((int) ($jar['types']['Information'] ?? 0) === 1 && (int) ($jar['types']['Support'] ?? 0) === 1, 'Information och Support räknas');
ls_ok((int) ($jar['needs_review'] ?? 0) === 2, 'låg confidence och needs_review räknas');
ls_ok((int) ($jar['manual'] ?? 0) === 6 && (int) ($jar['manual_lead'] ?? 0) === 6, 'manuella och manuella lead räknas');
ls_ok((int) ($jar['manual_lead_offer_reminder'] ?? 0) === 3, 'offert i kö eller sändlogg plus påminnelse på samma tråd');
ls_ok((int) ($mal['types']['Information'] ?? 0) === 1 && (int) ($mal['types']['Skräp'] ?? 0) === 1, 'Malmö räknas för sig');
ls_ok((int) ($mal['manual'] ?? 0) === 1 && (int) ($mal['manual_lead'] ?? 0) === 0 && (int) ($mal['needs_review'] ?? 0) === 0 && (int) ($mal['manual_lead_offer_reminder'] ?? 0) === 0, 'manuellt skräp i Malmö är inte ett lead');

ls_ok(!str_contains((string) $encoded, '@'), 'svaret har ingen e-post');
ls_ok(!str_contains((string) $encoded, $secretPhone) && !str_contains((string) $encoded, $secretName) && !str_contains((string) $encoded, 'Fejk'), 'svaret har varken nummer eller namn');
ls_ok(!str_contains((string) $encoded, 'thr-'), 'svaret har inga tråd-id');
ls_ok((int) $pdo->query('SELECT COUNT(*) FROM inkorg_messages')->fetchColumn() === $messagesBefore, 'stats skriver inga mejl');
ls_ok((int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn() === $remindersBefore, 'stats skriver inga påminnelser');
ls_ok((int) $pdo->query('SELECT COUNT(*) FROM inbox_sent')->fetchColumn() === $sentBefore, 'stats skriver ingen sändlogg');
ls_ok($queueCount() === $queueBefore, 'stats skriver inte kön');
ls_ok(jeab_inkorg_settings_cache_stamp() === $settingsBefore, 'stats skriver inte inställningar');
ls_ok((int) $pdo->query('SELECT COUNT(*) FROM inkorg_learn_queue')->fetchColumn() === 1, 'stats skriver inte inlärningskön');

$bad = jeab_inkorg_learn_stats(['since' => 'igår ' . $secretMail]);
$badRaw = json_encode($bad, JSON_UNESCAPED_UNICODE) ?: '';
ls_ok(($bad['ok'] ?? true) === false && ($bad['error'] ?? '') === 'since' && (int) ($bad['status'] ?? 0) === 422 && ($bad['read_only'] ?? false) === true, 'ogiltigt since nekas');
ls_ok(!str_contains($badRaw, '@') && !str_contains($badRaw, $secretMail), 'feltexten ekar inte indata');

$viaHook = jeab_inkorg_hook(['action' => 'inkorg.learn_stats', 'since' => $since]);
ls_ok(($viaHook['ok'] ?? false) === true && (int) ($viaHook['companies']['jarfalla']['manual_lead'] ?? 0) === 6, 'inkorg.learn_stats går via inkorgshooken utan admin');
$viaInbox = jeab_inkorg_hook(['action' => 'inbox.learn_stats', 'since' => $since]);
ls_ok(($viaInbox['ok'] ?? false) === true && (int) ($viaInbox['learn_queue'] ?? 0) === 1, 'inbox.learn_stats är samma anrop');

ls_ok(str_contains(implode("\n", jeab_auto_rule_lines()), 'inkorg.learn_stats'), 'regeln nämner hooken');
$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
ls_ok(str_contains($hookSrc, "str_starts_with(\$jeabHookAction, 'inkorg.')"), 'inkorg. byter namn till inbox. innan läshookarna');
ls_ok(!str_contains((string) file_get_contents(dirname(__DIR__) . '/src/inkorg.php'), "case 'inkorg.learn_stats':\n            jeab_inkorg_require_admin"), 'learn_stats kräver inte admin');

$inboxToken = 'ink_' . bin2hex(random_bytes(8));
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => $inboxToken]));

$httpRoot = sys_get_temp_dir() . '/jeab-learn-stats-http-' . bin2hex(random_bytes(4));
$httpEmpty = $httpRoot . '/empty';
mkdir($httpEmpty, 0775, true);
$router = $httpRoot . '/router.php';
$log = $httpRoot . '/php.log';
file_put_contents($router, <<<'PHP'
<?php
$data = getenv('JEAB_LS_DATA');
$root = getenv('JEAB_LS_ROOT');
if (!is_string($data) || $data === '' || !is_string($root) || $root === '') {
    http_response_code(500);
    echo 'missing';
    return true;
}
$GLOBALS['_JEAB_DATA_DIR'] = $data;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $data . '/public';
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path === '/hook.php') {
    require $root . '/hook.php';
    return true;
}
http_response_code(404);
echo 'no';
return true;
PHP);
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
ls_ok($probe !== false, 'kunde inte välja port');
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
$cmd = 'JEAB_LS_DATA=' . escapeshellarg($tmp)
    . ' JEAB_LS_ROOT=' . escapeshellarg(dirname(__DIR__))
    . ' ' . escapeshellarg(PHP_BINARY)
    . ' -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($log)
    . ' -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($httpEmpty)
    . ' ' . escapeshellarg($router);
$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
ls_ok(is_resource($proc), 'lokal PHP-server startade');
register_shutdown_function(static function () use ($proc): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
});
$up = false;
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $eNo, $eStr, 0.2);
    if (is_resource($fp)) {
        fclose($fp);
        $up = true;
        break;
    }
    usleep(100000);
}
ls_ok($up, 'testservern lyssnar');

$post = static function (string $action, array $payload, bool $auth) use ($port, $inboxToken): array {
    $headers = ['Content-Type: application/json'];
    if ($auth) {
        $headers[] = 'Authorization: Bearer ' . $inboxToken;
    }
    $ch = curl_init('http://127.0.0.1:' . $port . '/hook.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['action' => $action] + $payload),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $text = is_string($raw) ? $raw : '';

    return ['status' => $status, 'body' => $text, 'json' => json_decode($text, true)];
};

$denied = $post('inkorg.learn_stats', ['since' => $since], false);
ls_ok($denied['status'] === 401, 'hook.php utan token är 401');
$allowed = $post('inkorg.learn_stats', ['since' => $since, 'email' => $secretMail], true);
ls_ok($allowed['status'] === 200 && ($allowed['json']['ok'] ?? false) === true, 'hook.php med token är 200');
ls_ok((int) ($allowed['json']['companies']['jarfalla']['manual_lead_offer_reminder'] ?? 0) === 3, 'hook.php lämnar antalen');
ls_ok(!str_contains($allowed['body'], '@') && !str_contains($allowed['body'], $secretPhone) && !str_contains($allowed['body'], $inboxToken) && !str_contains($allowed['body'], $secretName), 'hook.php läcker varken adress, nummer, token eller namn');
$rejected = $post('inkorg.learn_stats', ['since' => '2026/01/01'], true);
ls_ok($rejected['status'] === 422 && ($rejected['json']['error'] ?? '') === 'since', 'hook.php nekar ogiltigt since');

echo "OK  inkorg.learn_stats\n";
