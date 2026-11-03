<?php

declare(strict_types=1);

/**
 * På/av per notis. Status i SQL. Avstängd notis lämnar inte send-vägen.
 * Påhittade mottagare. Inget riktigt utskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-notiser-switch-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa testkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';

$GLOBALS['_SWITCH_MAIL'] = 0;
$GLOBALS['_SWITCH_SMS'] = 0;
$GLOBALS['_SWITCH_GRAPH'] = 0;
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (): array {
    $GLOBALS['_SWITCH_MAIL']++;
    return ['ok' => true, 'path' => 'stub', 'id' => 'mail-stub'];
};
$GLOBALS['JEAB_SMS_SEND'] = static function (): array {
    $GLOBALS['_SWITCH_SMS']++;
    return ['ok' => true];
};
$GLOBALS['JEAB_GRAPH_SEND'] = static function (): array {
    $GLOBALS['_SWITCH_GRAPH']++;
    return ['ok' => true, 'from' => 'saljare4@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};

require dirname(__DIR__) . '/src/bootstrap.php';

function nsw(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

register_shutdown_function(static function () use ($tmp): void {
    if (!is_dir($tmp)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($tmp);
});

$who = 'redaktor@exempel.se';
$store = jeab_store();
nsw($store instanceof JeabPdoStore, 'butiken är SQL');
$pdo = $store->pdo();
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'notis_switches'")->fetchAll();
nsw($tables !== [], 'migreringen skapar notis_switches');
jeab_ensure_notis_switches($pdo, 'sqlite');
$again = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'notis_switches'")->fetchAll();
nsw(count($again) === 1, 'migreringen kan köras igen');

$settingsPath = jeab_inkorg_dir() . '/settings.json';
file_put_contents($settingsPath, json_encode([
    'helpscout_companies' => [],
    'graph_person_mail' => true,
    'quote_templates' => [
        'laddbox_jarfalla' => "Hej\n\nFejkad elbilsmall.",
        'batteri_jarfalla' => "Hej\n\nFejkad batterimall.",
        'laddbox_malmo' => "Hej\n\nFejkad malmöelbil.",
        'batteri_malmo' => "Hej\n\nFejkad malmöbatteri.",
        'sol_jarfalla' => '',
        'sol_malmo' => '',
    ],
], JSON_UNESCAPED_UNICODE));
file_put_contents(jeab_suresms_path(), json_encode([
    'login' => 'APIKey',
    'password' => 'suresms-hemlis-switch',
    'from' => 'JEAB',
    'from_malmo' => '+46700000099',
]));

nsw(jeab_notiser_is_on('bekraft-laddbox-jarfalla'), 'en notis utan rad är på');
$called = 0;
$open = jeab_notiser_send('bekraft-laddbox-jarfalla', static function () use (&$called): array {
    $called++;
    return ['ok' => true, 'path' => 'stub'];
});
nsw($called === 1 && !empty($open['ok']), 'påslagen notis når send');

jeab_notiser_set_enabled('bekraft-laddbox-jarfalla', false, $who);
nsw(!jeab_notiser_is_on('bekraft-laddbox-jarfalla'), 'avstängd notis är av');
$row = $pdo->prepare('SELECT enabled, updated_by FROM notis_switches WHERE notis_id = ?');
$row->execute(['bekraft-laddbox-jarfalla']);
$saved = $row->fetch();
nsw(is_array($saved) && (int) $saved['enabled'] === 0 && (string) $saved['updated_by'] === $who, 'på/av ligger i SQL');
$jsonPath = jeab_notiser_store_path();
nsw(!is_file($jsonPath) || !str_contains((string) file_get_contents($jsonPath), 'enabled'), 'på/av skrivs inte till JSON');

$blocked = 0;
$stopped = jeab_notiser_send('bekraft-laddbox-jarfalla', static function () use (&$blocked): array {
    $blocked++;
    return ['ok' => true];
});
nsw($blocked === 0 && !empty($stopped['skipped']) && ($stopped['reason'] ?? '') === 'notis-av', 'centrala grinden anropar inte send');

$before = jeab_notiser_counts(jeab_notiser_catalog());
$target = '';
foreach (jeab_notiser_catalog() as $item) {
    if ((string) ($item['id'] ?? '') === 'bekraft-laddbox-jarfalla') {
        continue;
    }
    if ((string) ($item['status'] ?? '') !== 'Av') {
        $target = (string) $item['id'];
        break;
    }
}
nsw($target !== '', 'det finns en notis som inte redan räknas som Av');
$targetStatus = '';
foreach (jeab_notiser_catalog() as $item) {
    if ((string) ($item['id'] ?? '') === $target) {
        $targetStatus = (string) $item['status'];
    }
}
jeab_notiser_set_enabled($target, false, $who);
$after = jeab_notiser_counts(jeab_notiser_catalog());
nsw($after['Av'] === $before['Av'] + 1, 'avstängd notis räknas under Av, före ' . $before['Av'] . ' efter ' . $after['Av']);
nsw($after[$targetStatus] === $before[$targetStatus] - 1, 'avstängd notis lämnar ' . $targetStatus);

$offRow = null;
foreach (jeab_notiser_catalog() as $item) {
    if ((string) ($item['id'] ?? '') === 'bekraft-laddbox-jarfalla') {
        $offRow = $item;
    }
}
nsw(is_array($offRow) && ($offRow['status'] ?? '') === 'Av' && empty($offRow['enabled']), 'avstängd bekräftelse visas som Av');

$rows = jeab_notiser_catalog();
$sol = jeab_notiser_filter($rows, ['pr' => 'sol', 'co' => '', 'ch' => '', 'st' => '']);
$bat = jeab_notiser_filter($rows, ['pr' => 'batteri', 'co' => '', 'ch' => '', 'st' => '']);
$box = jeab_notiser_filter($rows, ['pr' => 'laddbox', 'co' => 'jarfalla', 'ch' => 'mail', 'st' => '']);
nsw($sol !== [] && $bat !== [], 'produktfiltret träffar sol och batteri');
foreach ($sol as $item) {
    nsw((string) ($item['product'] ?? '') === 'sol', 'solfiltret släpper bara sol');
}
foreach ($box as $item) {
    nsw((string) ($item['product'] ?? '') === 'laddbox' && (string) ($item['company'] ?? '') === 'jarfalla' && (string) ($item['channel'] ?? '') === 'mail', 'produkt, bolag och kanal kan kombineras');
}
nsw(jeab_notiser_filter_from_request()['pr'] === '', 'okänd produkt blir tomt filter');
$_GET['pr'] = 'service';
$_GET['co'] = 'malmo';
$_GET['ch'] = 'sms';
$asked = jeab_notiser_filter_from_request();
nsw($asked['pr'] === 'service' && $asked['co'] === 'malmo' && $asked['ch'] === 'sms', 'filtret läser produkt, bolag och kanal');
unset($_GET['pr'], $_GET['co'], $_GET['ch']);

$filter = ['co' => '', 'ch' => '', 'st' => '', 'pr' => 'laddbox'];
$visible = jeab_notiser_filter($rows, $filter);
$counts = jeab_notiser_counts($rows);
$html = require dirname(__DIR__) . '/src/pages/notiser.php';
foreach (['Solpaneler', 'Batteri', 'Elbilsladdare', 'Service'] as $label) {
    nsw(str_contains($html, $label), 'chippet ' . $label . ' finns');
}
nsw(str_contains($html, 'pr=laddbox') && str_contains($html, 'pr=sol') && str_contains($html, 'pr=batteri') && str_contains($html, 'pr=service'), 'produktchipsen länkar');
nsw(str_contains($html, 'co=jarfalla') && str_contains($html, 'ch=mail') && str_contains($html, 'ch=sms'), 'bolag och kanal finns kvar');
nsw(str_contains($html, 'notiser.toggle') && str_contains($html, 'notis-toggle'), 'varje kort har en på/av-knapp');
nsw(str_contains($html, 'notis-fold') && str_contains($html, '<summary>'), 'kortet fälls ut');
nsw(str_contains($html, 'notis-name') && str_contains($html, 'notis-used') && str_contains($html, 'notis-status'), 'raden har namn, status och senast använd');
preg_match_all('/<summary>(.*?)<\/summary>/s', $html, $summaries);
nsw(($summaries[1] ?? []) !== [], 'det finns en sammanfattning');
foreach ($summaries[1] as $summary) {
    nsw(!str_contains($summary, 'Avsändare') && !str_contains($summary, '<textarea') && !str_contains($summary, 'Bilagor'), 'sammanfattningen är namn, kanal, bolag, status och senast använd');
}
$morePos = strpos($html, 'notis-more');
$senderPos = strpos($html, 'notis-sender');
$filesPos = strpos($html, 'Bilagor');
$bodyPos = strpos($html, 'Meddelande');
nsw($morePos !== false && $senderPos !== false && $senderPos > $morePos, 'avsändaren ligger i utfällningen');
nsw($filesPos !== false && $filesPos > $morePos, 'bilagorna ligger i utfällningen');
nsw($bodyPos !== false && $bodyPos > $morePos, 'meddelandet ligger i utfällningen');
nsw(!str_contains($html, 'notis-facts'), 'det breda faktarutnätet är borta');

$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/notiser.css');
nsw(str_contains($css, '@media (max-width: 720px)') && str_contains($css, 'min-height: 44px'), 'mobilvyn behåller träffytan');

$http = sys_get_temp_dir() . '/jeab-notiser-http-' . bin2hex(random_bytes(3));
mkdir($http, 0700, true);
file_put_contents($http . '/notiser.html', $html);
file_put_contents($http . '/notiser.css', $css);
$port = 0;
$server = null;
for ($try = 8091; $try < 8110; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
nsw($port > 0, 'en lokal port är ledig');
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $http],
    [1 => ['file', $http . '/server.log', 'a'], 2 => ['file', $http . '/server.log', 'a']],
    $pipes
);
nsw(is_resource($server), 'php-servern startade');
$ready = false;
for ($i = 0; $i < 20; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/notiser.html');
    if (is_string($probe) && str_contains($probe, 'Notiser till slutkund')) {
        $ready = true;
        break;
    }
    usleep(100000);
}
nsw($ready, 'sidan svarar');
$curlPage = curl_init('http://127.0.0.1:' . $port . '/notiser.html');
curl_setopt_array($curlPage, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$pageBody = (string) curl_exec($curlPage);
$pageCode = (int) curl_getinfo($curlPage, CURLINFO_HTTP_CODE);
curl_close($curlPage);
$curlCss = curl_init('http://127.0.0.1:' . $port . '/notiser.css');
curl_setopt_array($curlCss, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$cssBody = (string) curl_exec($curlCss);
$cssCode = (int) curl_getinfo($curlCss, CURLINFO_HTTP_CODE);
curl_close($curlCss);
nsw($pageCode === 200 && str_contains($pageBody, 'Elbilsladdare') && str_contains($pageBody, 'notiser.toggle') && str_contains($pageBody, 'pr=sol'), 'curl ser filter och på/av, fick ' . $pageCode);
nsw($cssCode === 200 && str_contains($cssBody, 'max-width: 720px') && str_contains($cssBody, 'notis-toggle'), 'curl ser mobilreglerna, fick ' . $cssCode);
if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}

$tz = new DateTimeZone(date_default_timezone_get());
$start = (new DateTimeImmutable('now', $tz))->modify('+5 hours');
$customer = $store->insertCustomer('Fejk Kund', $start->format('Y-m-d H:i:s'));
$booking = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Fejk Kund',
    'email' => 'fejk.kund@exempel.se',
    'phone' => '+46700000001',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => $start->format('Y-m-d H:i:s'),
    'ends_at' => $start->modify('+2 hours')->format('Y-m-d H:i:s'),
    'status' => 'booked',
    'created_at' => $start->format('Y-m-d H:i:s'),
    'updated_at' => $start->format('Y-m-d H:i:s'),
]);
nsw(jeab_notiser_booking_notice_id($booking, 'mail') === 'bekraft-laddbox-jarfalla', 'bekräftelsen pekar på rätt notis');
nsw(jeab_notiser_booking_notice_id($booking, 'sms') === 'sms-laddbox-jarfalla', 'SMS pekar på rätt notis');
$mailOff = jeab_booking_send_customer_mail($booking);
nsw(($mailOff['reason'] ?? '') === 'notis-av' && $GLOBALS['_SWITCH_MAIL'] === 0, 'avstängd bekräftelse skickar inget mejl');
jeab_notiser_set_enabled('sms-laddbox-jarfalla', false, $who);
$tickOff = jeab_sms_tick($store);
nsw((int) ($tickOff['sent'] ?? 0) === 0 && $GLOBALS['_SWITCH_SMS'] === 0 && $GLOBALS['_SWITCH_MAIL'] === 0, 'avstängt SMS lämnar inte tickern');

jeab_notiser_set_enabled('bekraft-laddbox-jarfalla', true, $who);
jeab_notiser_set_enabled('sms-laddbox-jarfalla', true, $who);
$mailOn = jeab_booking_send_customer_mail($booking);
nsw(!empty($mailOn['sent']) && $GLOBALS['_SWITCH_MAIL'] === 1, 'påslagen bekräftelse går genom grinden');
$tickOn = jeab_sms_tick($store);
nsw((int) ($tickOn['sent'] ?? 0) >= 1 && $GLOBALS['_SWITCH_SMS'] === 1, 'påslaget SMS går genom grinden');

$unknown = false;
try {
    jeab_notiser_set_enabled('inte-en-notis', false, $who);
} catch (InvalidArgumentException) {
    $unknown = true;
}
nsw($unknown, 'okänd notis kan inte sparas');

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => [
        'laddbox_jarfalla' => "Hej\n\nFejkad elbilsmall.",
        'batteri_jarfalla' => "Hej\n\nFejkad batterimall.",
        'laddbox_malmo' => "Hej\n\nFejkad malmöelbil.",
        'batteri_malmo' => "Hej\n\nFejkad malmöbatteri.",
        'sol_jarfalla' => '',
        'sol_malmo' => '',
    ],
]);
jeab_inkorg_helpscout_companies_save(['helpscout_companies' => []]);
jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode("%PDF-1.4 fejk\n")]);
$secretPath = jeab_graph_secret_path('m365.json');
if (!is_dir(dirname($secretPath))) {
    mkdir(dirname($secretPath), 0700, true);
}
file_put_contents($secretPath, json_encode([
    'tenant_id' => 'tenant-fejk',
    'client_id' => 'client-fejk',
    'client_secret' => 'graph-hemlis-switch',
]));
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn (): array => ['code' => 200, 'body' => ['value' => []]];
jeab_notiser_set_enabled('offert-laddbox-jarfalla', false, $who);
$graphAtIngest = $GLOBALS['_SWITCH_GRAPH'];
$lead = "Namn: Fejk Offert\nE-post: fejk.offert@exempel.se\nTelefon: 070-000 00 21\nAdress: Fejkgatan 1, 176 70 Järfälla\n";
$ingested = jeab_inkorg_ingest([
    'message_id' => 'switch-quote@exempel.se',
    'from' => 'info@wix-forms.com',
    'from_name' => 'Formulär',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Offert',
    'body_text' => $lead,
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'graph_id' => 'graph-switch-quote',
    'thread_key' => 'switch-quote',
    'preset_labels' => [
        'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
        'intent' => 'kop', 'offer' => 'laddbox', 'product' => 'laddbox', 'question' => '',
    ],
    'headers' => ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass'],
]);
$threadId = (string) ($ingested['thread_id'] ?? '');
nsw($threadId !== '', 'offertärendet finns');
nsw($GLOBALS['_SWITCH_GRAPH'] === $graphAtIngest, 'inkorgen skickar inte en avstängd offert vid mottagning');
$graphBeforeDry = $GLOBALS['_SWITCH_GRAPH'];
$dry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $threadId, 'dry' => 1]);
nsw(!empty($dry['ok']) && (int) ($dry['dry'] ?? 0) === 1 && str_contains((string) ($dry['body'] ?? ''), 'Fejkad elbilsmall'), 'förhandsvisning skickar inget, fick ' . (string) ($dry['error'] ?? $dry['why'] ?? $dry['action'] ?? ''));
nsw($GLOBALS['_SWITCH_GRAPH'] === $graphBeforeDry, 'förhandsvisningen rör inte utskicket');
$graphBefore = $GLOBALS['_SWITCH_GRAPH'];
$held = jeab_auto_on_message((string) ($ingested['id'] ?? ''));
nsw(($held['action'] ?? '') === 'hold' && str_contains((string) ($held['why'] ?? ''), 'avstängd'), 'avstängd offert hålls, fick ' . (string) ($held['action'] ?? '') . ' ' . (string) ($held['why'] ?? ''));
nsw($GLOBALS['_SWITCH_GRAPH'] === $graphBefore, 'avstängd offert anropar inte Graph');
jeab_notiser_set_enabled('offert-laddbox-jarfalla', true, $who);
$sentQuote = jeab_auto_on_message((string) ($ingested['id'] ?? ''));
nsw(($sentQuote['action'] ?? '') === 'quoted' && $GLOBALS['_SWITCH_GRAPH'] === $graphBefore + 1, 'påslagen offert går genom grinden, fick ' . (string) ($sentQuote['action'] ?? '') . ' ' . (string) ($sentQuote['why'] ?? ''));

echo "notiser-switch: ok\n";
