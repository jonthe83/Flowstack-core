<?php

declare(strict_types=1);

/** Omtago-webhook. Påhittade adresser. Ingen riktig nyckel. */

$tmp = sys_get_temp_dir() . '/jeab-omtago-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_OMTAGO_KEY_FILE'] = $tmp . '/omtago_api_key';
putenv('XAI_API_KEY');
unset($_ENV['XAI_API_KEY'], $_SERVER['XAI_API_KEY']);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-omtago-key',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
file_put_contents($GLOBALS['_JEAB_OMTAGO_KEY_FILE'], "test-omtago-key-7535\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function om(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
    echo "OK  {$message}\n";
}

$store = jeab_boot();
jeab_set_setting($store, 'inkorg_storage', 'sql');
om(jeab_inkorg_storage_mode() === 'sql', 'inkorgen ligger i SQL');

$savedKeyFile = $GLOBALS['_JEAB_OMTAGO_KEY_FILE'];
unset($GLOBALS['_JEAB_OMTAGO_KEY_FILE']);
$defaultKey = jeab_omtago_key_file();
$GLOBALS['_JEAB_OMTAGO_KEY_FILE'] = $savedKeyFile;
om(str_ends_with($defaultKey, '/privat/omtago_api_key'), 'nyckeln läses från privat/omtago_api_key');

$_SERVER['SCRIPT_NAME'] = '/api/omtago-lead.php';
om(jeab_public_script(), 'endpointen ligger utanför inloggningen');
$get = jeab_omtago_handle('GET', '', []);
$getBody = json_encode($get['body'], JSON_UNESCAPED_UNICODE);
om($get['status'] === 405 && is_string($getBody) && str_contains($getBody, 'POST') && !str_contains($getBody, 'kampanj'), 'GET ger 405 och ingen inloggningsruta');
$endpoint = (string) file_get_contents(dirname(__DIR__) . '/api/omtago-lead.php');
om(str_contains($endpoint, 'JEAB_NO_SESSION') && str_contains($endpoint, 'jeab_omtago_http'), 'endpointen är en egen POST-fil');

$classifyCalls = 0;
$GLOBALS['_JEAB_INKORG_LLM'] = static function () use (&$classifyCalls): string {
    $classifyCalls++;
    return '{"type":"Oklassad","confidence":10,"product":"","intent":"annat","offer":"","reason":"stub"}';
};
$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $args): array {
    $GLOBALS['_SENT'][] = $args;
    $n = count($GLOBALS['_SENT']);
    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g-om-' . $n, 'message_id' => 'sent-om-' . $n . '@local'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (): array {
    return ['code' => 500, 'body' => []];
};
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode('%PDF-1.4 batteri')]);
jeab_auto_pdf_save(['key' => 'sol_jarfalla', 'data' => base64_encode('%PDF-1.4 sol')]);

om(jeab_lead_source_id('leads@omtago.se') === 'omtago' && jeab_lead_source_blocked('info@omtago.se'), 'omtago.se är en leadkälla');
$blocked = jeab_outbound_graph_send('jarfalla', 'info@omtago.se', 'Offert', 'text');
om(empty($blocked['ok']) && ($blocked['error'] ?? '') === 'mottagaren är en leadkälla' && $GLOBALS['_SENT'] === [], 'utskick till omtago.se stoppas');

/** @param array<string,mixed> $over */
function om_lead(array $over = []): array
{
    $base = [
        'lead_id' => 'omt-1001',
        'created_at' => '2026-10-20T10:00:00+02:00',
        'lead_type' => 'Batteri',
        'property_type' => 'Villa',
        'contact' => [
            'first_name' => 'Nora',
            'last_name' => 'Exempel',
            'email' => 'nora.omtago@gmail.com',
            'phone' => '070-000 00 21',
        ],
        'address' => [
            'street' => 'Exempelvägen 12',
            'city' => 'Teststad',
            'postal_code' => '177 80',
        ],
        'summary' => 'Vill ha batteri till villan.',
        'profile' => [['label' => 'Tak', 'value' => 'Tegel']],
        'test' => false,
    ];
    return array_replace_recursive($base, $over);
}

/** @param array<string,mixed> $payload @param array<string,mixed> $server */
function om_post(array $payload, array $server = []): array
{
    $server += [
        'REMOTE_ADDR' => '203.0.113.50',
        'HTTP_AUTHORIZATION' => 'Bearer test-omtago-key-7535',
    ];
    return jeab_omtago_handle('POST', (string) json_encode($payload, JSON_UNESCAPED_UNICODE), $server);
}

$noKey = om_post(om_lead(), ['REMOTE_ADDR' => '203.0.113.11', 'HTTP_AUTHORIZATION' => '', 'HTTP_X_API_KEY' => '']);
om($noKey['status'] === 401 && ($noKey['body']['error'] ?? '') === 'Fel nyckel.', 'saknad nyckel ger 401');
$badKey = om_post(om_lead(), ['REMOTE_ADDR' => '203.0.113.12', 'HTTP_AUTHORIZATION' => 'Bearer fel-nyckel']);
om($badKey['status'] === 401, 'fel nyckel ger 401');
$shortKey = om_post(om_lead(), ['REMOTE_ADDR' => '203.0.113.13', 'HTTP_AUTHORIZATION' => 'Bearer kort']);
om($shortKey['status'] === 401, 'nyckel med fel längd ger 401');
rename($GLOBALS['_JEAB_OMTAGO_KEY_FILE'], $GLOBALS['_JEAB_OMTAGO_KEY_FILE'] . '.borta');
$missingFile = om_post(om_lead(), ['REMOTE_ADDR' => '203.0.113.14', 'HTTP_AUTHORIZATION' => 'Bearer test-omtago-key-7535']);
om($missingFile['status'] === 401, 'saknad nyckelfil ger 401');
rename($GLOBALS['_JEAB_OMTAGO_KEY_FILE'] . '.borta', $GLOBALS['_JEAB_OMTAGO_KEY_FILE']);

$mysqlRate = jeab_omtago_rate_upsert_sql('mysql');
om(!str_contains($mysqlRate, 'ON CONFLICT'), 'mysql-rate använder inte ON CONFLICT');
om(str_contains(jeab_omtago_rate_upsert_sql('sqlite'), 'ON CONFLICT'), 'sqlite-rate använder ON CONFLICT');
$omtagoSrc = (string) file_get_contents(dirname(__DIR__) . '/src/omtago.php');
$gateStart = strpos($omtagoSrc, 'function jeab_omtago_rate_gate');
$gateEnd = strpos($omtagoSrc, 'function jeab_omtago_strip_country');
$gateSrc = substr($omtagoSrc, (int) $gateStart, (int) $gateEnd - (int) $gateStart);
om(!str_contains($gateSrc, 'ON CONFLICT'), 'rate-grinden förbereder inte SQLite-SQL');
$handleStart = strpos($omtagoSrc, 'function jeab_omtago_handle');
$handleSrc = substr($omtagoSrc, (int) $handleStart, 1800);
om(
    strpos($handleSrc, 'jeab_omtago_key_ok') < strpos($handleSrc, 'jeab_omtago_pdo'),
    'nyckeln kontrolleras före SQL'
);

$broken = jeab_omtago_pdo();
$broken->exec('DROP TABLE omtago_rate');
$broken->exec('CREATE TABLE omtago_rate (ip_hash TEXT PRIMARY KEY)');
$noKeyBroken = om_post(om_lead(['lead_id' => 'omt-no-db']), [
    'REMOTE_ADDR' => '203.0.113.15',
    'HTTP_AUTHORIZATION' => '',
    'HTTP_X_API_KEY' => '',
]);
om($noKeyBroken['status'] === 401, 'saknad nyckel ger 401 även när SQL faller');
$badKeyBroken = om_post(om_lead(['lead_id' => 'omt-bad-db']), [
    'REMOTE_ADDR' => '203.0.113.16',
    'HTTP_AUTHORIZATION' => 'Bearer syns-inte-i-loggen',
]);
om($badKeyBroken['status'] === 401, 'fel nyckel ger 401 även när SQL faller');
$logDir = jeab_data_dir() . '/fellogg-omtago';
mkdir($logDir, 0775, true);
$GLOBALS['_JEAB_FELLOGG_DIR'] = $logDir;
$dbFail = om_post(om_lead(['lead_id' => 'omt-db-fail']), ['REMOTE_ADDR' => '203.0.113.17']);
om($dbFail['status'] === 503, 'godkänd nyckel och trasig rate-tabell ger 503');
$logged = (string) @file_get_contents(jeab_app_errors_path());
om(str_contains($logged, 'window_start'), 'det verkliga SQL-felet loggas');
om(!str_contains($logged, 'test-omtago-key-7535') && !str_contains($logged, 'syns-inte-i-loggen'), 'loggen innehåller ingen nyckel');
unset($GLOBALS['_JEAB_FELLOGG_DIR']);
$broken->exec('DROP TABLE omtago_rate');
jeab_omtago_ensure_tables($broken, 'sqlite');

$badType = om_post(om_lead(['lead_type' => 'Vindkraft']));
om($badType['status'] === 400 && str_contains((string) ($badType['body']['error'] ?? ''), 'lead_type'), 'ogiltig lead_type ger 400');
$badTest = om_post(om_lead(['test' => 'true']));
om($badTest['status'] === 400 && str_contains((string) ($badTest['body']['error'] ?? ''), 'test'), 'test som sträng ger 400');
$onlyCountry = om_post(om_lead([
    'lead_id' => 'omt-country',
    'address' => ['street' => 'Exempelvägen 1', 'city' => 'Sverige', 'postal_code' => '17780'],
]));
om($onlyCountry['status'] === 400 && str_contains((string) ($onlyCountry['body']['error'] ?? ''), 'Sverige'), 'orten Sverige ger 400');
$countryRow = jeab_omtago_pdo()->prepare('SELECT lead_id FROM omtago_leads WHERE lead_id = ?');
$countryRow->execute(['omt-country']);
om($countryRow->fetch() === false, 'avvisad ort skrivs inte i SQL');

$apiKey = om_post(om_lead([
    'lead_id' => 'omt-header',
    'contact' => [
        'first_name' => 'Hedda',
        'last_name' => 'Exempel',
        'email' => 'hedda.omtago@example.test',
        'phone' => '070-000 00 24',
    ],
]), [
    'REMOTE_ADDR' => '203.0.113.16',
    'HTTP_AUTHORIZATION' => '',
    'HTTP_X_API_KEY' => 'test-omtago-key-7535',
]);
om($apiKey['status'] === 200 && ($apiKey['body']['lead_id'] ?? '') === 'omt-header', 'X-Api-Key godkänns');

$callsBefore = $classifyCalls;
$sentBeforeTest = count($GLOBALS['_SENT']);
$msgPath = jeab_inkorg_dir() . '/messages.json';
$msgBefore = is_file($msgPath) ? (string) filemtime($msgPath) . ':' . (string) filesize($msgPath) : 'missing';
$testLead = om_post(om_lead([
    'lead_id' => 'omt-test',
    'contact' => [
        'first_name' => 'Test',
        'last_name' => 'Testsson',
        'email' => 'test.omtago@example.test',
        'phone' => '0700000000',
    ],
    'test' => true,
]), ['REMOTE_ADDR' => '203.0.113.60', 'HTTP_AUTHORIZATION' => 'Bearer test-omtago-key-7535']);
om($testLead['status'] === 200 && ($testLead['body']['ok'] ?? false) === true && ($testLead['body']['id'] ?? '') !== '', 'test=true svarar med id');
om($classifyCalls === $callsBefore && count($GLOBALS['_SENT']) === $sentBeforeTest, 'test=true klassar inte och skickar ingen offert');
$testId = (string) $testLead['body']['id'];
$testMeta = jeab_inkorg_thread_meta($testId);
om((string) ($testMeta['created_at'] ?? '') !== '' && (!empty($testMeta['test_lead']) || in_array('test', array_map('strval', (array) ($testMeta['tags'] ?? [])), true)), 'test=true skapar ärendet och märker det Test');
$seenTest = false;
$seenMarked = false;
foreach (jeab_inkorg_messages() as $row) {
    if (str_contains((string) ($row['from'] ?? ''), 'test.omtago@example.test')) {
        $seenTest = true;
        $seenMarked = !empty($row['labels']['is_test']) || in_array('test', array_map('strval', (array) ($row['labels']['add_tags'] ?? [])), true);
    }
}
om($seenTest && $seenMarked, 'test=true skapar mejlet och märker det Test');
$testSql = jeab_omtago_pdo()->prepare('SELECT thread_id, test FROM omtago_leads WHERE lead_id = ?');
$testSql->execute(['omt-test']);
$testStored = $testSql->fetch();
om(is_array($testStored) && (string) $testStored['thread_id'] === $testId && (int) $testStored['test'] === 1, 'test=true loggas i SQL');
$testAgain = om_post(om_lead([
    'lead_id' => 'omt-test',
    'contact' => [
        'first_name' => 'Test',
        'last_name' => 'Testsson',
        'email' => 'test.omtago@example.test',
        'phone' => '0700000000',
    ],
    'test' => true,
]), ['REMOTE_ADDR' => '203.0.113.60', 'HTTP_AUTHORIZATION' => 'Bearer test-omtago-key-7535']);
om(($testAgain['body']['id'] ?? '') === $testId, 'test=true är idempotent');

$sentBefore = count($GLOBALS['_SENT']);
$callsBefore = $classifyCalls;
$live = om_post(om_lead());
om($live['status'] === 200 && ($live['body']['ok'] ?? false) === true, 'lead tas emot');
$liveId = (string) ($live['body']['id'] ?? '');
om($liveId !== '' && ($live['body']['lead_id'] ?? '') === 'omt-1001', 'svaret innehåller tråd-id och lead_id');
om($classifyCalls === $callsBefore + 1, 'klassningen körs');
$liveMsg = null;
foreach (jeab_inkorg_messages() as $row) {
    if ((string) ($row['thread_id'] ?? '') === $liveId) {
        $liveMsg = $row;
    }
}
om(is_array($liveMsg), 'tråden finns i inkorgen');
om((string) ($liveMsg['from'] ?? '') === 'nora.omtago@gmail.com' && (string) ($liveMsg['from_name'] ?? '') === 'Nora Exempel', 'avsändaren är kunden');
om((string) ($liveMsg['labels']['type'] ?? '') === 'Ny lead' && (string) ($liveMsg['labels']['offer'] ?? '') === 'batteri', 'typ Ny lead och produkt batteri');
om(!str_contains((string) ($liveMsg['body'] ?? ''), 'Sverige'), 'brödtexten innehåller inte Sverige');
$meta = jeab_inkorg_thread_meta($liveId);
$recipient = '';
$liveContact = trim((string) ($meta['contact_id'] ?? ''));
if ($liveContact !== '' && function_exists('jeab_kontakt_get')) {
    $liveRow = jeab_kontakt_get($liveContact);
    if (is_array($liveRow) && function_exists('jeab_kontakt_row_email')) {
        $recipient = jeab_kontakt_row_email($liveRow);
    }
}
if ($recipient === '') {
    $recipient = (string) ($meta['customer_email'] ?? '');
}
om((string) ($meta['lead_source'] ?? '') === 'omtago' && $recipient === 'nora.omtago@gmail.com', 'källan är Omtago och mottagaren är kunden');
$msgAfter = is_file($msgPath) ? (string) filemtime($msgPath) . ':' . (string) filesize($msgPath) : 'missing';
om($msgAfter === $msgBefore, 'tråden skrivs inte till messages.json');
$sqlStore = jeab_inkorg_pdo_store();
$byThread = $sqlStore instanceof JeabPdoInkorgStore ? $sqlStore->findMessagesByThread($liveId) : [];
om($sqlStore instanceof JeabPdoInkorgStore && count($byThread) >= 1, 'tråden ligger i SQL');
$threadCount = count($byThread);
$sent = $GLOBALS['_SENT'][$sentBefore] ?? [];
om(count($GLOBALS['_SENT']) === $sentBefore + 1 && ($sent['to'] ?? '') === 'nora.omtago@gmail.com', 'autoofferten går till kunden');
om(!str_contains((string) ($sent['to'] ?? ''), '@omtago.se'), 'autoofferten går inte till Omtago');

$again = om_post(om_lead());
om(($again['body']['id'] ?? '') === $liveId && count($sqlStore->findMessagesByThread($liveId)) === $threadCount && count($GLOBALS['_SENT']) === $sentBefore + 1, 'dubblett ger samma id utan ny tråd eller ny offert');

$place = om_post(om_lead([
    'lead_id' => 'omt-place',
    'contact' => [
        'first_name' => 'Bo',
        'last_name' => 'Exempel',
        'email' => 'bo.omtago@example.test',
        'phone' => '070-000 00 22',
    ],
    'address' => [
        'street' => 'Exempelvägen 14, Sverige',
        'city' => 'Teststad, Sverige',
        'postal_code' => '17780',
    ],
    'summary' => 'Hej från Sverige',
    'profile' => [
        ['label' => 'Land', 'value' => 'Sverige'],
        ['label' => 'Tak', 'value' => 'Plåt'],
    ],
]), ['REMOTE_ADDR' => '203.0.113.70', 'HTTP_AUTHORIZATION' => 'Bearer test-omtago-key-7535']);
om($place['status'] === 200, 'ort med landet efter sig tas emot');
$placeId = (string) ($place['body']['id'] ?? '');
$placeMsg = null;
foreach (jeab_inkorg_messages() as $row) {
    if ((string) ($row['thread_id'] ?? '') === $placeId) {
        $placeMsg = $row;
    }
}
om(is_array($placeMsg) && str_contains((string) ($placeMsg['body'] ?? ''), 'Teststad') && str_contains((string) ($placeMsg['body'] ?? ''), 'Exempelvägen 14') && !str_contains((string) ($placeMsg['body'] ?? ''), 'Sverige'), 'sparad text har gata och ort utan Sverige');

$blob = (string) ($placeMsg['body'] ?? '');
foreach (jeab_inkorg_contacts(true) as $contact) {
    if (!is_array($contact)) {
        continue;
    }
    $blob .= json_encode($contact, JSON_UNESCAPED_UNICODE);
}
foreach (jeab_kontakter_load() as $contact) {
    if (!is_array($contact)) {
        continue;
    }
    $blob .= json_encode($contact, JSON_UNESCAPED_UNICODE);
}
$reminders = jeab_store()->pdo()->query('SELECT customer_name, email, address, ort FROM reminders');
foreach ($reminders ? $reminders->fetchAll() : [] as $reminder) {
    if (is_array($reminder)) {
        $blob .= json_encode($reminder, JSON_UNESCAPED_UNICODE);
    }
}
om(str_contains($blob, 'Exempelvägen 14') && str_contains($blob, 'Teststad') && !str_contains($blob, 'Sverige') && !str_contains($blob, 'Sweden'), 'kontakt och påminnelse har gata och ort, aldrig Sverige');
$boContact = false;
foreach (jeab_inkorg_contacts(true) as $contact) {
    if (!is_array($contact)) {
        continue;
    }
    $emails = array_map('strval', (array) ($contact['emails'] ?? []));
    if (in_array('bo.omtago@example.test', $emails, true)) {
        $boContact = str_contains((string) ($contact['address'] ?? ''), 'Exempelvägen 14')
            && str_contains((string) ($contact['town'] ?? '') . (string) ($contact['city'] ?? ''), 'Teststad');
    }
}
if (!$boContact) {
    foreach (jeab_kontakter_load() as $contact) {
        if (!is_array($contact)) {
            continue;
        }
        $encoded = json_encode($contact, JSON_UNESCAPED_UNICODE);
        if (is_string($encoded) && str_contains($encoded, 'bo.omtago@example.test')) {
            $boContact = str_contains((string) ($contact['street'] ?? ''), 'Exempelvägen 14')
                && str_contains((string) ($contact['city'] ?? ''), 'Teststad');
        }
    }
}
om($boContact, 'kontakten fick gata och ort');

$sentBefore = count($GLOBALS['_SENT']);
$skane = om_post(om_lead([
    'lead_id' => 'omt-skane',
    'lead_type' => 'Solceller',
    'contact' => [
        'first_name' => 'Kim',
        'last_name' => 'Exempel',
        'email' => 'kim.omtago@example.test',
        'phone' => '070-000 00 23',
    ],
    'address' => [
        'street' => 'Exempelgatan 2',
        'city' => 'Malmö',
        'postal_code' => '211 22',
    ],
    'summary' => 'Vill ha solceller.',
]), ['REMOTE_ADDR' => '203.0.113.80', 'HTTP_AUTHORIZATION' => 'Bearer test-omtago-key-7535']);
om($skane['status'] === 200 && count($GLOBALS['_SENT']) === $sentBefore, 'Skåne-sol går genom områdesreglerna och skickas inte');

function om_guard_count(): int
{
    try {
        $pdo = jeab_store()->pdo();
    } catch (Throwable) {
        return 0;
    }
    $n = 0;
    foreach (['inbox_sent', 'send_queue'] as $table) {
        $has = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table));
        if (!$has || $has->fetchColumn() === false) {
            continue;
        }
        $n += (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    return $n;
}

/** @param array<string,mixed> $over */
function om_assert_open_test(array $over, string $email, string $phone, string $label): void
{
    $guardBefore = om_guard_count();
    $sentBefore = count($GLOBALS['_SENT']);
    $smsBefore = count((array) ($GLOBALS['_SMS'] ?? []));
    $res = om_post(om_lead($over), [
        'REMOTE_ADDR' => '203.0.113.' . (string) random_int(120, 180),
        'HTTP_AUTHORIZATION' => 'Bearer test-omtago-key-7535',
    ]);
    om($res['status'] === 200 && ($res['body']['ok'] ?? false) === true, $label . ' tas emot');
    $tid = (string) ($res['body']['id'] ?? '');
    $meta = jeab_inkorg_thread_meta($tid);
    $tags = array_map('strval', (array) ($meta['tags'] ?? []));
    om($tid !== '' && (string) ($meta['status'] ?? 'open') === 'open' && empty($meta['deleted_at']), $label . ' ligger öppet');
    om(!empty($meta['test_lead']) && in_array('test', $tags, true), $label . ' har taggen test');
    om(count($GLOBALS['_SENT']) === $sentBefore && count((array) ($GLOBALS['_SMS'] ?? [])) === $smsBefore && om_guard_count() === $guardBefore, $label . ' skriver inget i send_guard');
    $mid = '';
    foreach (jeab_inkorg_messages() as $row) {
        if ((string) ($row['thread_id'] ?? '') === $tid) {
            $mid = (string) ($row['id'] ?? '');
        }
    }
    $auto = $mid !== '' ? jeab_auto_on_message($mid) : ['action' => ''];
    om(($auto['action'] ?? '') !== 'historik' && ($auto['why'] ?? '') === 'testlead' && (int) ($auto['sent'] ?? 0) === 0, $label . ' stängs inte som egen');
    om((string) (jeab_inkorg_thread_meta($tid)['status'] ?? '') === 'open', $label . ' är kvar i Inkorgen efter automationen');
    $row = [
        'id' => $tid,
        'status' => 'open',
        'test_lead' => !empty($meta['test_lead']),
        'tags' => $tags,
        'updated_at' => (string) ($meta['updated_at'] ?? ''),
        'company' => 'jarfalla',
        'first' => ['subject' => 'Ny lead'],
    ];
    $html = jeab_inkorg_fast_list_html([$row], '', '', ['p' => 'inkorg'], [], 1, 1);
    om(str_contains($html, 'is-test') && str_contains($html, '>Test<'), $label . ' får chippen Test i listan');
    om(jeab_inkorg_test_chip_html($row, 'ix-chip is-test') === '<span class="ix-chip is-test">Test</span>', $label . ' får chippen Test i huvudet');
    $plain = [
        'id' => 'vanligt',
        'status' => 'open',
        'tags' => ['batteri'],
        'updated_at' => (string) ($meta['updated_at'] ?? ''),
        'company' => 'jarfalla',
        'first' => ['subject' => 'Ny lead'],
    ];
    $plainHtml = jeab_inkorg_fast_list_html([$plain], '', '', ['p' => 'inkorg'], [], 1, 1);
    om(!str_contains($plainHtml, 'is-test'), 'ett vanligt ärende har ingen Test-chip');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_AUTHORIZATION'] = '';
    $_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
    $denied = jeab_omtago_lead_status_dispatch(['lead_id' => (string) ($over['lead_id'] ?? '')]);
    om(($denied['http_status'] ?? 0) === 401 && ($denied['error'] ?? '') === 'Fel token.', $label . ' status kräver token');
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-the-omtago-key';
    $missing = jeab_omtago_lead_status_dispatch([]);
    om(($missing['http_status'] ?? 0) === 400, 'status utan id ger 400');
    $status = jeab_omtago_lead_status_dispatch(['lead_id' => (string) ($over['lead_id'] ?? '')]);
    $byId = jeab_omtago_lead_status_dispatch(['id' => $tid]);
    $blob = (string) json_encode($status, JSON_UNESCAPED_UNICODE);
    om(($status['ok'] ?? false) === true && ($status['thread_id'] ?? '') === $tid && ($byId['thread_id'] ?? '') === $tid, $label . ' status hittas på lead_id och id');
    om(($status['test'] ?? false) === true && ($status['status'] ?? '') === 'open' && ($status['folder'] ?? '') === 'inkorg' && ($status['list_status'] ?? '') === 'open' && ($status['historik'] ?? true) === false, $label . ' status säger öppen Inkorg');
    om(($status['company'] ?? '') === 'jarfalla' && in_array('test', (array) ($status['tags'] ?? []), true), $label . ' status har bolag och taggen test');
    om(!str_contains($blob, $email) && !str_contains($blob, $phone) && !str_contains($blob, '@'), $label . ' status har inga personuppgifter');
    $GLOBALS['_OM_TEST_IDS'][] = $tid;
}

om(jeab_inkorg_shows_test(['test_lead' => true, 'tags' => []]) && jeab_inkorg_shows_test(['tags' => ['test']]) && !jeab_inkorg_shows_test(['tags' => ['batteri']]), 'chippen kräver test_lead eller taggen test');
$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
om(str_contains($hookSrc, "=== 'omtago.lead_status'") && str_contains($hookSrc, "=== 'omtago.reopen_test'") && str_contains($hookSrc, "=== 'omtago.key_status'"), 'hookarna omtago.lead_status, omtago.reopen_test och omtago.key_status finns');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = '';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
unset($_POST['token']);
$noKey = jeab_omtago_key_status_dispatch();
$noKeyBlob = (string) json_encode($noKey, JSON_UNESCAPED_UNICODE);
om(($noKey['http_status'] ?? 0) === 401 && ($noKey['error'] ?? '') === 'Fel token.' && !array_key_exists('length', $noKey) && !array_key_exists('exists', $noKey) && !str_contains($noKeyBlob, 'test-omtago-key-7535'), 'key_status utan token är 401 och visar inget');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-the-omtago-key';
$keyStatus = jeab_omtago_key_status_dispatch();
unset($keyStatus['http_status']);
$keyBlob = (string) json_encode($keyStatus);
om($keyStatus === ['exists' => true, 'length' => 20] && $keyBlob === '{"exists":true,"length":20}' && !str_contains($keyBlob, 'test-omtago-key-7535'), 'key_status säger bara att nyckeln finns och hur lång den är');
$savedKeyPath = $GLOBALS['_JEAB_OMTAGO_KEY_FILE'];
$GLOBALS['_JEAB_OMTAGO_KEY_FILE'] = $savedKeyPath . '-saknas';
$missingKey = jeab_omtago_key_status_dispatch();
unset($missingKey['http_status']);
om($missingKey === ['exists' => false, 'length' => 0], 'saknad nyckelfil är exists false');
file_put_contents($savedKeyPath . '-blank', "  \n");
$GLOBALS['_JEAB_OMTAGO_KEY_FILE'] = $savedKeyPath . '-blank';
$blankKey = jeab_omtago_key_status_dispatch();
unset($blankKey['http_status']);
om($blankKey === ['exists' => false, 'length' => 0], 'tom nyckelfil är exists false');
$GLOBALS['_JEAB_OMTAGO_KEY_FILE'] = $savedKeyPath;
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-the-omtago-key';
$GLOBALS['_OM_TEST_IDS'] = [];

$ownGuard = om_guard_count();
om_assert_open_test([
    'lead_id' => 'omt-own-addr',
    'contact' => [
        'first_name' => 'Maja',
        'last_name' => 'Holm',
        'email' => 'demo@example.test',
        'phone' => '0707654321',
    ],
    'test' => true,
], 'demo@example.test', '0707654321', 'test=true med egen adress');
om(om_guard_count() === $ownGuard, 'egen adress lämnar send_guard orörd');

om_assert_open_test([
    'lead_id' => 'omt-jeab-test',
    'contact' => [
        'first_name' => 'Erik',
        'last_name' => 'Holm',
        'email' => 'inkorg+test-inkorg@example.test',
        'phone' => '0701112233',
    ],
    'test' => true,
], 'inkorg+test-inkorg@example.test', '0701112233', 'test=true med inkorg+test');

$ownId = (string) ($GLOBALS['_OM_TEST_IDS'][0] ?? '');
$liveBefore = (string) (jeab_inkorg_thread_meta($liveId)['status'] ?? '');
$refused = jeab_omtago_reopen_test_dispatch(['id' => $liveId, 'dry' => 0]);
om(empty($refused['ok']) && ($refused['error'] ?? '') === 'inte ett testlead' && (string) (jeab_inkorg_thread_meta($liveId)['status'] ?? '') === $liveBefore, 'ett vanligt lead öppnas inte');
jeab_inkorg_klassning_close($ownId, 'egen');
$closed = jeab_omtago_lead_status_dispatch(['id' => $ownId]);
om(($closed['historik'] ?? false) === true && ($closed['status'] ?? '') === 'closed' && ($closed['folder'] ?? '') === 'historik', 'stängt testlead syns i Historik');
$sentBeforeReopen = count($GLOBALS['_SENT']);
$guardBeforeReopen = om_guard_count();
$dry = jeab_omtago_reopen_test_dispatch(['lead_id' => 'omt-own-addr']);
om(($dry['dry'] ?? 0) === 1 && ($dry['reopened'] ?? true) === false && ($dry['would_folder'] ?? '') === 'inkorg' && (string) (jeab_inkorg_thread_meta($ownId)['status'] ?? '') === 'closed', 'dry=1 är standard och skriver inte');
$opened = jeab_omtago_reopen_test_dispatch(['id' => 'omt-own-addr', 'dry' => 0]);
om(($opened['reopened'] ?? false) === true && ($opened['dry'] ?? 1) === 0 && ($opened['status'] ?? '') === 'open' && ($opened['folder'] ?? '') === 'inkorg' && ($opened['list_status'] ?? '') === 'open' && ($opened['historik'] ?? true) === false, 'dry=0 flyttar testleadet till Inkorgen');
om((string) (jeab_inkorg_thread_meta($ownId)['status'] ?? '') === 'open' && empty(jeab_inkorg_thread_meta($ownId)['deleted_at']), 'återöppnat testlead är öppet');
om(count($GLOBALS['_SENT']) === $sentBeforeReopen && om_guard_count() === $guardBeforeReopen, 'återöppning skickar inget');
$reopenLogs = jeab_lead_sql()->query("SELECT payload FROM log_lines WHERE kind = 'omtago_reopen'")->fetchAll();
$reopenBlob = '';
foreach ($reopenLogs ?: [] as $reopenRow) {
    $reopenBlob .= (string) ($reopenRow['payload'] ?? '');
}
om(is_array($reopenLogs) && $reopenLogs !== [] && str_contains($reopenBlob, '"dry":1') && str_contains($reopenBlob, '"dry":0') && !str_contains($reopenBlob, '@'), 'återöppningen loggas utan personuppgifter');
$openedBlob = (string) json_encode($opened, JSON_UNESCAPED_UNICODE);
om(!str_contains($openedBlob, 'demo@example.test') && !str_contains($openedBlob, '0707654321'), 'återöppningens svar har inga personuppgifter');

$GLOBALS['_JEAB_OMTAGO_RATE_MAX'] = 2;
$rateServer = ['REMOTE_ADDR' => '203.0.113.99', 'HTTP_AUTHORIZATION' => 'Bearer test-omtago-key-7535'];
$first = jeab_omtago_handle('POST', '{', $rateServer);
$second = jeab_omtago_handle('POST', '{', $rateServer);
$third = jeab_omtago_handle('POST', '{', $rateServer);
om($first['status'] === 400 && $second['status'] === 400 && $third['status'] === 429, 'rate-limit svarar 429');
$unauth = jeab_omtago_handle('POST', '{', ['REMOTE_ADDR' => '203.0.113.99', 'HTTP_AUTHORIZATION' => 'Bearer fel-nyckel']);
om($unauth['status'] === 401, 'fel nyckel är 401 även när IP:t är över gränsen');

echo "omtago lead ok\n";
