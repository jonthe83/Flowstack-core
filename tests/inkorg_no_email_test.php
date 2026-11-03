<?php

declare(strict_types=1);

/** Lead utan kundmejl: ingen offert, en påminnelse, ett SMS. Upprepning skickar inte igen. */

$tmp = sys_get_temp_dir() . '/jeab-no-email-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ne(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['_SMS'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;
    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g-ne', 'message_id' => 'sent-ne@local'];
};
$GLOBALS['JEAB_SMS_SEND'] = static function (string $to, string $text, ?string $company = null, ?string $from = null): array {
    $GLOBALS['_SMS'][] = ['to' => $to, 'text' => $text, 'company' => $company, 'from' => $from];
    return ['ok' => true];
};

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode('%PDF-1.4 batteri')]);
jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode('%PDF-1.4 ladd')]);
jeab_auto_pdf_save(['key' => 'sol_jarfalla', 'data' => base64_encode('%PDF-1.4 sol')]);

ne(jeab_no_email_owner('batteri', 'jarfalla') === 'Roberto', 'batteri är Roberto');
ne(jeab_no_email_owner('laddbox', 'jarfalla') === 'Elin', 'elbilsladdare är Elin');
ne(jeab_no_email_owner('sol', 'jarfalla') === 'Sam L', 'sol är Sam L');
ne(jeab_no_email_owner('sol+batteri', 'jarfalla') === 'Sam L', 'sol och batteri är Sam L');
ne(jeab_no_email_owner('batteri', 'malmo') === 'Kim' && jeab_no_email_owner('laddbox', 'malmo') === 'Kim' && jeab_no_email_owner('sol', 'malmo') === 'Kim', 'Malmö är Kim');
ne(jeab_no_email_sms_body('Roberto') === 'Får jag din mejladress så skickar jag offert Roberto', 'SMS-texten har säljarens namn');
ne(str_contains(implode("\n", jeab_auto_rule_lines()), 'inbox.no_email_scan'), 'regeln nämner hooken');

$box = jeab_graph_mailboxes()['jarfalla'];
$labels = static function (string $offer): array {
    return ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => $offer, 'lead' => 'kopt', 'product' => $offer, 'confidence' => 100, 'question' => ''];
};
$take = static function (string $id, string $body, string $offer, string $company = 'jarfalla') use ($box, $labels): array {
    $to = $company === 'malmo' ? jeab_graph_mailboxes()['malmo'] : $box;
    return jeab_inkorg_ingest([
        'message_id' => $id . '@lead.test',
        'from' => 'support@weply.se',
        'from_name' => 'Weply',
        'to' => $to,
        'subject' => 'Ny lead ' . $offer,
        'body_text' => $body,
        'source' => $company === 'malmo' ? 'graph-malmo' : 'graph-jarfalla',
        'thread_key' => 'ne-' . $id,
        'graph_mailbox' => $to,
        'company' => $company,
        'headers' => ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass header.from=weply.se'],
        'preset_labels' => $labels($offer),
    ]);
};

$phoneBody = "Namn: Nora Exempel\nTelefon: 070-111 22 33\nProdukt: Batteri\nVill ha batteri.\n";
$beforeMail = count($GLOBALS['_SENT']);
$beforeSms = count($GLOBALS['_SMS']);
$in = $take('phone', $phoneBody, 'batteri');
ne(!empty($in['ok']), 'lead med bara telefon tas emot');
$auto = jeab_auto_on_message((string) $in['id']);
ne(($auto['action'] ?? '') !== 'quoted' && count($GLOBALS['_SENT']) === $beforeMail, 'ingen offert utan mejl');
ne(count($GLOBALS['_SMS']) === $beforeSms + 1, 'ett SMS');
$sms = $GLOBALS['_SMS'][$beforeSms];
ne($sms['text'] === 'Får jag din mejladress så skickar jag offert Roberto' && $sms['company'] === 'jarfalla' && $sms['to'] === '+46701112233' && $sms['from'] === null, 'SMS går från batterisäljaren till mobilnumret');
$tid = (string) ($in['thread_id'] ?? '');
$again = jeab_auto_on_message((string) $in['id']);
$phoneThread = ['id' => $tid, 'tags' => [], 'messages' => [], 'first' => ['from' => 'support@weply.se', 'body' => $phoneBody, 'labels' => $labels('batteri')]];
$applyAgain = jeab_no_email_apply($phoneThread, $phoneThread['first'], false);
$rems = [];
foreach (jeab_list_reminders(jeab_store(), true) as $row) {
    if (str_contains((string) ($row['note'] ?? ''), 'ring kunden och ta mejladressen') && str_contains((string) ($row['note'] ?? ''), $tid)) {
        $rems[] = $row;
    }
}
ne(count($rems) === 1 && in_array('Roberto', (array) ($rems[0]['assignees'] ?? []), true) && (string) ($rems[0]['email'] ?? '') === '', 'en påminnelse till Roberto utan kundmejl');
unset($again);
ne(count($GLOBALS['_SMS']) === $beforeSms + 1 && ($applyAgain['sms'] ?? '') === 'redan skickat', 'upprepning skickar inget nytt SMS');
$pdo = jeab_lead_sql();
ne($pdo instanceof PDO, 'SQL finns');
$stored = $pdo->query('SELECT thread_id, created_at FROM inbox_no_email_sms')->fetchAll();
$storedJson = json_encode($stored);
ne(is_string($storedJson) && !str_contains($storedJson, '070') && !str_contains($storedJson, '+467') && !str_contains($storedJson, '@') && count($stored) === 1, 'dedupe-nyckeln är bara tråd-id');
ne(!is_file($tmp . '/no-email.json') && !is_file($tmp . '/inbox_no_email_sms.json'), 'ingen JSON-nyckel');

$quietBody = "Namn: Utan Mobil\nProdukt: Elbilsladdare\nVill ha laddbox.\n";
$smsNow = count($GLOBALS['_SMS']);
$quiet = $take('quiet', $quietBody, 'laddbox');
$quietAuto = jeab_auto_on_message((string) $quiet['id']);
ne(($quietAuto['action'] ?? '') !== 'quoted' && count($GLOBALS['_SENT']) === $beforeMail && count($GLOBALS['_SMS']) === $smsNow, 'utan mobil skickas varken offert eller SMS');
$quietTid = (string) ($quiet['thread_id'] ?? '');
$quietRem = null;
foreach (jeab_list_reminders(jeab_store(), true) as $row) {
    if (str_contains((string) ($row['note'] ?? ''), $quietTid) && str_contains((string) ($row['note'] ?? ''), 'ring kunden och ta mejladressen')) {
        $quietRem = $row;
    }
}
ne(is_array($quietRem) && in_array('Elin', (array) ($quietRem['assignees'] ?? []), true), 'utan mobil får Elin ändå påminnelsen');

$dry = jeab_inkorg_hook(['action' => 'inbox.no_email_scan']);
$dryJson = json_encode($dry);
ne(!empty($dry['ok']) && (int) ($dry['dry'] ?? 0) === 1 && ($dry['would_send'] ?? true) === false && is_string($dryJson) && !str_contains($dryJson, '070') && !str_contains($dryJson, '+467') && !str_contains($dryJson, '@'), 'dry=1 listar utan telefon eller mejl');
$sawPhone = false;
$sawQuiet = false;
foreach ((array) ($dry['leads'] ?? []) as $lead) {
    if ((string) ($lead['thread_id'] ?? '') === $tid) {
        $sawPhone = ($lead['owner'] ?? '') === 'Roberto' && ($lead['sms'] ?? '') === 'redan skickat' && ($lead['quote'] ?? true) === false && ($lead['reminder'] ?? '') === 'ring kunden och ta mejladressen';
    }
    if ((string) ($lead['thread_id'] ?? '') === $quietTid) {
        $sawQuiet = ($lead['owner'] ?? '') === 'Elin' && ($lead['sms'] ?? '') === 'ingen mobil' && ($lead['quote'] ?? true) === false;
    }
}
ne($sawPhone && $sawQuiet, 'dry-listan visar påminnelse och om SMS skulle gå');
$noConfirm = jeab_inkorg_hook(['action' => 'inbox.no_email_scan', 'dry' => 0]);
ne(empty($noConfirm['ok']) && ($noConfirm['error'] ?? '') === 'confirm' && count($GLOBALS['_SMS']) === $smsNow, 'dry=0 utan confirm skickar inte');

$malmoBody = "Namn: Malmö Kund\nTelefon: 070-333 44 55\nProdukt: Batteri\n";
$smsBeforeMalmo = count($GLOBALS['_SMS']);
$malmo = $take('malmo', $malmoBody, 'batteri', 'malmo');
$malmoAuto = jeab_auto_on_message((string) $malmo['id']);
ne(($malmoAuto['action'] ?? '') !== 'quoted' && count($GLOBALS['_SENT']) === $beforeMail && count($GLOBALS['_SMS']) === $smsBeforeMalmo + 1, 'Malmö utan mejl får inget offertmejl');
$malmoSms = $GLOBALS['_SMS'][$smsBeforeMalmo];
ne($malmoSms['text'] === 'Får jag din mejladress så skickar jag offert Kim' && $malmoSms['company'] === 'malmo', 'Malmö-SMS kommer från Kim');

unset($GLOBALS['JEAB_SMS_SEND']);
file_put_contents(jeab_data_file('suresms.json'), json_encode(['password' => 'fixture-not-a-key']));
$guardPdo = jeab_store()->pdo();
jeab_send_guard_ensure($guardPdo);
$guardPdo->exec('UPDATE send_guard_state SET killed = 1 WHERE id = 1');
$stopped = jeab_sms_send('+46704445566', jeab_no_email_sms_body('Kim'), 'malmo', null, [
    'guard_template' => 'no_email',
    'guard_mode' => 'auto',
    'guard_via' => 'auto',
    'guard_actor' => 'system',
]);
ne(($stopped['ok'] ?? true) === false && ($stopped['error'] ?? '') === 'Automatiska utskick är stoppade.', 'nödstoppet stoppar SMS-vägen');
$stopBody = "Namn: Stopp Kund\nTelefon: 070-444 55 66\nProdukt: Sol\n";
$stoppedLead = $take('stop', $stopBody, 'sol');
$stoppedRow = jeab_no_email_apply(
    ['id' => (string) ($stoppedLead['thread_id'] ?? ''), 'first' => [], 'tags' => [], 'messages' => [], 'brand' => 'jarfalla'],
    ['from' => 'support@weply.se', 'body' => $stopBody, 'body_text' => $stopBody, 'labels' => $labels('sol')],
    false
);
ne(($stoppedRow['sms'] ?? '') === 'stoppad' && ($stoppedRow['owner'] ?? '') === 'Sam L', 'stoppat lead skickar inget och solen är Sam L');
$left = $pdo->prepare('SELECT COUNT(*) FROM inbox_no_email_sms WHERE thread_id = ?');
$left->execute([(string) ($stoppedLead['thread_id'] ?? '')]);
ne((int) $left->fetchColumn() === 0, 'misslyckat SMS lämnar ingen dedupe-nyckel');
@unlink(jeab_data_file('suresms.json'));
$guardPdo->exec('UPDATE send_guard_state SET killed = 0 WHERE id = 1');

$inboxToken = jeab_inkorg_token_ensure();
$router = $tmp . '/router.php';
file_put_contents($router, <<<'PHP'
<?php
declare(strict_types=1);
$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_NE_DATA') ?: '';
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path === '/hook.php') {
    require (getenv('JEAB_NE_ROOT') ?: '') . '/hook.php';
    return true;
}
http_response_code(404);
echo 'saknas';
return true;
PHP);
$port = 18000 + random_int(0, 2000);
$proc = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
    [1 => ['file', $tmp . '/server.out', 'w'], 2 => ['file', $tmp . '/server.err', 'w']],
    $pipes,
    $tmp,
    ['JEAB_NE_DATA' => $tmp, 'JEAB_NE_ROOT' => dirname(__DIR__)] + getenv()
);
ne(is_resource($proc), 'hook-servern startade');
$ping = '';
for ($i = 0; $i < 30; $i++) {
    $ping = (string) @file_get_contents('http://127.0.0.1:' . $port . '/hook.php');
    if (str_contains($ping, '"ok"')) {
        break;
    }
    usleep(100000);
}
$payload = json_encode(['action' => 'inbox.no_email_scan', 'dry' => 1], JSON_UNESCAPED_UNICODE);
$curl = 'curl -sS -D - -o ' . escapeshellarg($tmp . '/curl-body.txt')
    . ' -X POST http://127.0.0.1:' . $port . '/hook.php'
    . ' -H ' . escapeshellarg('Content-Type: application/json')
    . ' -H ' . escapeshellarg('Authorization: Bearer ' . $inboxToken)
    . ' --data ' . escapeshellarg((string) $payload);
$curlOut = (string) shell_exec($cmd = $curl);
unset($cmd);
proc_terminate($proc);
proc_close($proc);
$body = is_file($tmp . '/curl-body.txt') ? (string) file_get_contents($tmp . '/curl-body.txt') : '';
$decoded = json_decode($body, true);
$safeBody = str_replace($inboxToken, 'TOKEN', $body);
$safeHead = str_replace($inboxToken, 'TOKEN', $curlOut);
ne(str_contains($ping, '"ok"'), 'hook.php svarar');
ne(is_array($decoded) && !empty($decoded['ok']) && (int) ($decoded['dry'] ?? 0) === 1 && ($decoded['would_send'] ?? true) === false, 'curl dry=1 listar utan att skicka, fick ' . (is_array($decoded) ? (string) ($decoded['error'] ?? 'ok') : 'tomt svar'));
ne(!str_contains($safeBody, '070') && !str_contains($safeBody, '+467') && !str_contains($safeBody, '@') && !str_contains($safeBody, 'fixture-not-a-key'), 'curl-svaret har ingen adress, telefon eller nyckel');
ne(!str_contains($safeHead, $inboxToken) && !str_contains($safeBody, 'TOKEN'), 'curl-utskriften visar inte token');

echo "no email ok\n";
