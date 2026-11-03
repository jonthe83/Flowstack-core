<?php

declare(strict_types=1);

/** Köpintresse blir Ny lead. Omklassning och Malmö-hsi är dry som standard. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-reclass-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function rc(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$store = jeab_store();
rc($store instanceof JeabPdoStore, 'sqlite är uppe');
$tables = $store->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'inbox_reclassify_audit'")->fetchAll();
rc($tables !== [], 'db.php skapar inbox_reclassify_audit');

$prompt = jeab_inkorg_llm_prompt(['from' => 'kund@example.com', 'subject' => 'Hej', 'body' => 'Hej']);
rc(str_contains((string) ($prompt[0]['content'] ?? ''), 'alltid type Ny lead') && str_contains((string) ($prompt[0]['content'] ?? ''), 'aldrig type Information'), 'prompten säger att köpintresse är Ny lead');
rc(str_contains(jeab_inkorg_seed_settings()['intent'], 'Köpintresse är alltid Ny lead'), 'instruktionen säger samma sak');
$rules = implode("\n", jeab_auto_rule_lines());
rc(str_contains($rules, 'inbox.reclassify_leads') && str_contains($rules, 'inbox.malmo_hsi_quotes') && str_contains($rules, 'reminders.lookup_hsi'), 'reglerna nämner de tre hookarna');

$info = static function (string $kind = 'information'): string {
    return json_encode([
        'type' => 'Information',
        'confidence' => 90,
        'intent' => 'information',
        'case_intent' => '',
        'kind' => $kind,
        'primary_product' => '',
        'reason' => 'info',
        'field_confidence' => [
            'intent' => 0.95, 'kind' => 0.95, 'type' => 0.95, 'primary_product' => 0.95,
            'is_lead_seller' => 0.95, 'label' => 0.95,
        ],
    ], JSON_UNESCAPED_UNICODE);
};
jeab_inkorg_locked('settings.json', static function (?array $d): array {
    $d = is_array($d) ? $d : [];
    $d['grok_decides'] = true;
    return [true, $d];
});
$GLOBALS['_JEAB_INKORG_LLM'] = static fn (): string => $info();
$lead = jeab_inkorg_classify([
    'from' => 'kund.batt@brevlada.se',
    'subject' => 'Offert batteri',
    'body' => 'Hej, vi vill köpa och installera ett batteri. Kan vi få pris?',
    'source' => 'test-unit',
]);
rc(($lead['type'] ?? '') === 'Information' && ($lead['case_class']['type'] ?? '') === 'Information' && ($lead['case_class']['kind'] ?? '') === 'information' && ($lead['offer'] ?? '') === 'batteri' && ($lead['case_class']['primary_product'] ?? '') === 'Batteri', 'modellen sa Information, köpord fyller produkt utan att byta typ');
$support = jeab_inkorg_classify([
    'from' => 'form@example.com',
    'subject' => 'Support',
    'body' => "Vad gäller det: Support till befintlig kund\nOffert på solceller",
    'source' => 'test-unit',
]);
rc(($support['type'] ?? '') === 'Support', 'support-kryss förblir Support');
$order = jeab_inkorg_classify([
    'from' => 'order@lev.example',
    'subject' => 'Orderbekräftelse',
    'body' => 'Batteri skickas enligt orderbekräftelsen.',
    'source' => 'test-unit',
]);
rc(($order['type'] ?? '') === 'Information', 'orderbekräftelse förblir Information');
unset($GLOBALS['_JEAB_INKORG_LLM']);

$bare = jeab_hs_import_classify(['subject' => 'Hej', 'tags' => []]);
rc(($bare['type'] ?? '') === 'Information' && ($bare['tags'] ?? []) === [], 'import utan köp och utan etikett är Information');
$tagged = jeab_hs_import_classify(['subject' => 'Offert', 'jeab_text' => 'Vi vill installera ett batteri och få en offert.', 'tags' => []]);
rc(($tagged['type'] ?? '') === 'Information' && in_array('batteri', (array) ($tagged['tags'] ?? []), true), 'import med köpintresse fyller produktetikett utan Ny lead');

$mail = static function (string $id, string $body, string $type, string $when): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@reclass.test',
        'from' => 'kund.' . $id . '@brevlada.se',
        'from_name' => 'Test',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Förfrågan',
        'body_text' => $body,
        'received_at' => $when,
        'source' => 'test-reclass',
        'preset_labels' => [
            'type' => $type, 'action' => 'arkivera', 'priority' => 4, 'confidence' => 40, 'intent' => 'information',
        ],
        'test' => true,
    ]);
};
$old = $mail('old', 'Kan jag få pris på ett batteri?', 'Information', date('c', time() - 40 * 86400));
$hit = $mail('hit', 'Kan jag få pris på ett batteri?', 'Information', date('c'));
$other = $mail('other', 'Tack för senast.', 'Information', date('c'));
rc(!empty($old['ok']) && !empty($hit['ok']) && !empty($other['ok']), 'tre ärenden intagna');

$dry = jeab_inkorg_hook(['action' => 'inbox.reclassify_leads']);
rc(($dry['ok'] ?? false) === true && ($dry['dry'] ?? 0) === 1 && ($dry['days'] ?? 0) === 14 && ($dry['audited'] ?? 1) === 0, 'reclassify är dry i 14 dagar');
$ids = array_column((array) ($dry['rows'] ?? []), 'thread_id');
rc($ids === [(string) $hit['thread_id']], 'bara det färska köpintresset listas: ' . implode(',', $ids));
$dryRow = (array) ($dry['rows'][0] ?? []);
rc(($dryRow['old_type'] ?? '') === 'Information' && ($dryRow['new_type'] ?? '') === 'Ny lead' && ($dryRow['offer'] ?? '') === 'batteri', 'raden säger Information till Ny lead, batteri');
rc(($dryRow['would_quote'] ?? true) === false && ($dryRow['would_send'] ?? true) === false && ($dryRow['would_remind'] ?? true) === false, 'raden skulle varken offerera, skicka eller påminna');
rc(str_contains(json_encode($dry, JSON_UNESCAPED_UNICODE) ?: '', '@') === false, 'dry-listan har ingen e-post');
rc(jeab_inkorg_get((string) $hit['id'])['labels']['type'] === 'Information', 'dry skriver inte om typen');

$apply = jeab_inkorg_hook(['action' => 'inbox.reclassify_leads', 'dry' => 0, 'days' => 14]);
rc(($apply['ok'] ?? false) === true && ($apply['dry'] ?? 1) === 0 && (int) ($apply['audited'] ?? 0) === 1, 'dry=0 skriver en auditrad');
rc(jeab_inkorg_get((string) $hit['id'])['labels']['type'] === 'Ny lead', 'typen är Ny lead');
rc(jeab_inkorg_get((string) $old['id'])['labels']['type'] === 'Information', 'äldre ärende rörs inte');
$auditN = (int) $store->pdo()->query('SELECT COUNT(*) FROM inbox_reclassify_audit')->fetchColumn();
rc($auditN === 1, 'SQL har en rad');

$hsi = static function (string $n, string $day, string $body, string $offerHint): string {
    unset($offerHint);
    $tid = 'hsi-' . substr(hash('sha256', 'reclass-' . $n), 0, 16);
    $conv = [
        'id' => $tid,
        'company' => 'malmo',
        'status' => 'closed',
        'import_source' => 'helpscout_import',
        'no_auto' => true,
        'hs_ids' => ['conversation' => '88' . sprintf('%03d', (int) $n), 'number' => '88' . sprintf('%03d', (int) $n)],
        'messages' => [[
            'id' => substr(hash('sha256', 'm-' . $n), 0, 16),
            'from' => 'arkiv@brevlada.se',
            'from_name' => 'Arkiv',
            'subject' => 'Förfrågan',
            'body' => $body,
            'received_at' => $day . 'T09:00:00+02:00',
            'source' => 'helpscout_import',
            'company' => 'malmo',
            'labels' => ['type' => 'Information', 'action' => 'arkivera', 'priority' => 4, 'source' => 'import'],
            'no_auto' => true,
        ]],
        'meta' => [
            'status' => 'closed',
            'import_source' => 'helpscout_import',
            'no_auto' => true,
            'hs_ids' => ['conversation' => '88' . sprintf('%03d', (int) $n), 'number' => '88' . sprintf('%03d', (int) $n)],
        ],
    ];
    rc(jeab_hs_import_save_conv($conv) && jeab_hs_import_index_append(jeab_hs_import_index_row($conv)), 'hsi ' . $n . ' sparas');
    return $tid;
};
$d7 = $hsi('7', '2026-10-07', 'Vi vill ha offert på batteri i Malmö.', 'batteri');
$d14 = $hsi('14', '2026-10-14', 'Vad kostar en laddbox?', 'laddbox');
$d17 = $hsi('17', '2026-10-17', 'Vi vill installera batteri.', 'batteri');
$sol = $hsi('3', '2026-10-14', 'Offert på solceller tack.', 'sol');
$listed = jeab_inkorg_hook([
    'action' => 'inbox.malmo_hsi_quotes',
    'dates' => ['2026-10-07', '2026-10-14', '2026-10-17'],
]);
rc(($listed['ok'] ?? false) === true && ($listed['dry'] ?? 0) === 1 && ($listed['count'] ?? 0) === 3, 'tre Malmö-batteri/laddbox, inte sol');
rc(str_contains((string) ($listed['cause'] ?? ''), 'helpscout_import') && str_contains((string) ($listed['cause'] ?? ''), 'malmo_hs_since'), 'orsaken nämner hsi och datumgränsen');
$byThread = [];
foreach ((array) ($listed['rows'] ?? []) as $row) {
    $byThread[(string) $row['thread_id']] = $row;
}
rc(isset($byThread[$d7], $byThread[$d14], $byThread[$d17]) && !isset($byThread[$sol]), '7/10, 14/10 och 17/10 är med');
rc(($byThread[$d7]['would_send'] ?? true) === false && in_array('hsi_no_auto', (array) $byThread[$d7]['reasons'], true) && in_array('type_not_lead', (array) $byThread[$d7]['reasons'], true) && in_array('since_open_only', (array) $byThread[$d7]['reasons'], true), 'orsakerna är hsi, typ och öppen-sök');
rc(str_contains(json_encode($listed, JSON_UNESCAPED_UNICODE) ?: '', '@') === false, 'hsi-listan har ingen e-post');
$refused = jeab_inkorg_hook(['action' => 'inbox.malmo_hsi_quotes', 'dry' => 0]);
rc(($refused['ok'] ?? true) === false && ($refused['error'] ?? '') === 'bara_dry', 'dry=0 nekas och skickar inget');

$rem = jeab_create_reminder($store, 'Batteri', '2026-10-17 09:00:00', "HS#88017\nInkorgsärende https://flowstack.example.test/index.php?p=inkorg&id=" . $d17, null, [
    'company' => 'malmo', 'who' => 'Kim',
]);
$store->saveReminder((int) $rem['id'], ['thread_id' => $d17]);
$noteBefore = (string) (jeab_get_reminder($store, (int) $rem['id'])['note'] ?? '');
$byThreadId = jeab_reminders_lookup_hsi($store, ['thread_id' => $d17]);
$byHs = jeab_reminders_lookup_hsi($store, ['hs_id' => '88017']);
rc(($byThreadId['read_only'] ?? false) === true && ($byThreadId['count'] ?? 0) === 1 && (int) ($byThreadId['reminders'][0]['id'] ?? 0) === (int) $rem['id'], 'thread_id hittar påminnelsen');
rc(($byHs['count'] ?? 0) === 1 && in_array('hs', (array) ($byHs['reminders'][0]['matched'] ?? []), true), 'hs-id hittar samma påminnelse');
rc((string) (jeab_get_reminder($store, (int) $rem['id'])['note'] ?? '') === $noteBefore, 'uppslaget skriver inte');
rc((jeab_reminders_lookup_hsi($store, [])['ok'] ?? true) === false, 'tomt anrop nekas');
rc(str_contains(json_encode($byHs, JSON_UNESCAPED_UNICODE) ?: '', '@') === false, 'uppslaget har ingen e-post');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-historik'];
jeab_inkorg_thread_write((string) $hit['thread_id'], static function (array $t): array {
    $t['status'] = 'closed';
    return $t;
});
if (!function_exists('jeab_flash_html')) {
    function jeab_flash_html(?string $flash): string
    {
        return '';
    }
}
if (!function_exists('jeab_error_html')) {
    function jeab_error_html(?string $error): string
    {
        return '';
    }
}
$html = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
rc(str_contains($html, 'class="ix-cat is-lead">Lead</span>'), 'Historik visar lead-etiketten på raden');

$inboxToken = 'ink_' . bin2hex(random_bytes(8));
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => $inboxToken]));
$httpRoot = sys_get_temp_dir() . '/jeab-reclass-http-' . bin2hex(random_bytes(4));
mkdir($httpRoot, 0775, true);
$router = $httpRoot . '/router.php';
$log = $httpRoot . '/php.log';
file_put_contents($router, <<<'PHP'
<?php
$data = getenv('JEAB_RC_DATA');
$root = getenv('JEAB_RC_ROOT');
if (!is_string($data) || $data === '' || !is_string($root) || $root === '') {
    http_response_code(500);
    echo 'missing';
    return true;
}
$GLOBALS['_JEAB_DATA_DIR'] = $data;
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
rc($probe !== false, 'port');
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
$cmd = 'JEAB_RC_DATA=' . escapeshellarg($tmp)
    . ' JEAB_RC_ROOT=' . escapeshellarg(dirname(__DIR__))
    . ' ' . escapeshellarg(PHP_BINARY)
    . ' -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($log)
    . ' -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($httpRoot)
    . ' ' . escapeshellarg($router);
$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
rc(is_resource($proc), 'server');
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
rc($up, 'servern lyssnar');

$curl = static function (string $action, array $extra, bool $auth) use ($port, $inboxToken): array {
    $body = json_encode(['action' => $action] + $extra, JSON_UNESCAPED_UNICODE);
    $headers = ['Content-Type: application/json'];
    if ($auth) {
        $headers[] = 'Authorization: Bearer ' . $inboxToken;
    }
    $ch = curl_init('http://127.0.0.1:' . $port . '/hook.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $text = is_string($raw) ? $raw : '';
    $json = json_decode($text, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : [], 'body' => $text];
};
$denied = $curl('inbox.reclassify_leads', [], false);
rc($denied['status'] === 401, 'reclassify utan token är 401');
$ok = $curl('inbox.reclassify_leads', [], true);
rc($ok['status'] === 200 && ($ok['json']['dry'] ?? 0) === 1 && str_contains($ok['body'], '@') === false, 'curl reclassify är dry och utan e-post');
$hsiCurl = $curl('inbox.malmo_hsi_quotes', ['dates' => ['2026-10-07', '2026-10-14', '2026-10-17']], true);
rc($hsiCurl['status'] === 200 && ($hsiCurl['json']['count'] ?? 0) === 3 && ($hsiCurl['json']['rows'][0]['would_send'] ?? true) === false, 'curl hsi-listan skickar inget');
$lookDenied = $curl('reminders.lookup_hsi', ['hs_id' => '88017'], false);
rc($lookDenied['status'] === 401, 'lookup utan token är 401');
$look = $curl('reminders.lookup_hsi', ['thread_id' => $d17], true);
rc($look['status'] === 200 && ($look['json']['read_only'] ?? false) === true && ($look['json']['count'] ?? 0) === 1 && str_contains($look['body'], '@') === false, 'curl lookup är läsning utan e-post');

echo "OK  reclassify\n";
