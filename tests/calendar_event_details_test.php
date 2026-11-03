<?php

declare(strict_types=1);

/**
 * Kalenderhändelsen får besöksadress, telefon, e-post, orderns anteckning
 * och hela påminnelsetexten. Backfill rör bara framtida händelsers plats och text.
 */

$tmp = sys_get_temp_dir() . '/jeab-cal-details-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

function det_ok(bool $cond, string $message): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
    echo "OK  {$message}\n";
}

$calls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body = null, array $headers = []) use (&$calls) {
    unset($headers);
    $calls[] = [$method, $url, $body];
    if ($method === 'POST' && str_contains($url, '/events')) {
        return ['code' => 201, 'body' => ['id' => 'evt-created']];
    }
    if ($method === 'PATCH') {
        return ['code' => 200, 'body' => ['id' => 'evt-patched']];
    }
    if ($method === 'DELETE') {
        return ['code' => 204, 'body' => []];
    }

    return ['code' => 500, 'body' => ['error' => ['message' => 'oväntat anrop']]];
};

$store = jeab_store();
det_ok($store instanceof JeabPdoStore, 'testet kör SQLite');

$tz = new DateTimeZone('Europe/Stockholm');
$now = new DateTimeImmutable('now', $tz);
$futureStart = $now->modify('+14 days')->setTime(0, 0);
$futureEnd = $futureStart->modify('+5 days');
$liveStart = $now->modify('-2 days')->setTime(0, 0);
$liveEnd = $now->modify('+4 days')->setTime(0, 0);
$pastStart = $now->modify('-30 days')->setTime(0, 0);
$pastEnd = $pastStart->modify('+1 day');

$secret = 'hook-' . bin2hex(random_bytes(8));
@mkdir($tmp . '/secrets', 0700, true);
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => $secret]));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = $secret;

$pnr = '19800101-1234';
$note = "Första stycket om taket.\n\nAndra stycket om ställningen. Kunden nämnde " . $pnr . " i telefon.";
$phone = '0701234567';
$email = 'kund@example.test';
$install = 'Nyckel under mattan vid sidodörren.';

$GLOBALS['_JEAB_QUIET_BOOKING'] = true;
$booking = jeab_create_booking($store, 'Bokad Kund', ['Niman'], $futureStart->format('Y-m-d H:i:s'), $futureEnd->format('Y-m-d H:i:s'), 'sol', [
    'company' => 'jarfalla',
    'assignees' => ['Niman'],
]);
$live = jeab_create_booking($store, 'Pågående Kund', ['Niman'], $liveStart->format('Y-m-d H:i:s'), $liveEnd->format('Y-m-d H:i:s'), 'batteri', [
    'company' => 'jarfalla',
    'assignees' => ['Niman'],
    'address' => 'Batterivägen 2',
    'phone' => '0707654321',
    'email' => 'batteri@example.test',
]);
$past = jeab_create_booking($store, 'Gammal Kund', ['Niman'], $pastStart->format('Y-m-d H:i:s'), $pastEnd->format('Y-m-d H:i:s'), 'laddbox', [
    'company' => 'jarfalla',
    'assignees' => ['Niman'],
    'address' => 'Gamla gatan 1',
]);
$bare = jeab_create_booking($store, 'Utan händelse', ['Elin'], $futureStart->modify('+1 day')->format('Y-m-d H:i:s'), $futureEnd->format('Y-m-d H:i:s'), 'service', [
    'company' => 'jarfalla',
    'assignees' => ['Elin'],
    'address' => 'Servicegatan 4',
    'phone' => '0701112233',
    'email' => 'service@example.test',
]);
unset($GLOBALS['_JEAB_QUIET_BOOKING']);

$bookingId = (int) $booking['id'];
$nowSql = jeab_now();
$reminder = $store->insertReminder([
    'title' => 'Solvägen',
    'customer_name' => 'Bokad Kund',
    'email' => $email,
    'phone' => $phone,
    'address' => null,
    'note' => $note,
    'booking_id' => $bookingId,
    'completed_at' => $nowSql,
    'company' => 'jarfalla',
    'created_at' => $nowSql,
    'updated_at' => $nowSql,
]);
det_ok($store->findOpenReminderByBooking($bookingId) === null, 'klar påminnelse räknas inte som öppen');

$GLOBALS['_JEAB_PURCHASE_LIST'] = [
    (string) $reminder['id'] => [
        'reminder_id' => (int) $reminder['id'],
        'booking_id' => $bookingId,
        'kind' => 'sol',
        'gata' => 'Solvägen 12',
        'postnummer' => '17740',
        'ort' => 'Järfälla',
        'install_what' => '18 paneler och växelriktare',
        'install_note' => $install,
        'phone' => '',
        'email' => '',
    ],
];

$fresh = $store->getBooking($bookingId);
det_ok(is_array($fresh), 'bokningen finns');
$body = jeab_gp_event_body($fresh);
$desc = (string) ($body['body']['content'] ?? '');
$location = (string) ($body['location']['displayName'] ?? '');
det_ok($location === 'Solvägen 12, 177 40 Järfälla', 'platsfältet är besöksadressen');
det_ok(str_contains((string) ($body['subject'] ?? ''), 'Solvägen 12'), 'ny händelse har adressen i rubriken');
det_ok(str_contains($desc, "Besöksadress\n" . $location), 'brödtexten har besöksadressen');
det_ok(str_contains($desc, "Telefon\n") && str_contains($desc, '1234567'), 'brödtexten har telefonen');
det_ok(str_contains($desc, $email), 'brödtexten har e-post');
det_ok(str_contains($desc, '18 paneler och växelriktare') && str_contains($desc, $install), 'brödtexten har orderns installation');
det_ok(str_contains($desc, "Första stycket om taket.\n\nAndra stycket om ställningen."), 'hela påminnelsetexten följer med');
det_ok(str_contains($desc, 'påbörjar installation av solceller'), 'solbrevets hela text följer med');
det_ok(!str_contains($desc, '19800101') && !str_contains($desc, $pnr), 'personnummer skrivs inte i händelsen');
det_ok(($body['attendees'] ?? null) === [], 'ny händelse har inga deltagare');
det_ok(!isset($body['attendees'][0]), 'deltagarlistan är tom');

$letters = [
    'batteri' => 'sätter upp ditt batteri',
    'laddbox' => 'sätter upp din laddbox',
    'service' => 'Bokad tid:',
];
foreach ($letters as $job => $phrase) {
    $sample = [
        'id' => 0,
        'customer_name' => 'Prov Kund',
        'email' => 'prov@example.test',
        'phone' => '0700000000',
        'address' => 'Provgatan 1',
        'job_type' => $job,
        'starts_at' => $futureStart->format('Y-m-d H:i:s'),
        'ends_at' => $futureStart->modify('+1 day')->format('Y-m-d H:i:s'),
        'status' => 'booked',
        'company' => 'jarfalla',
    ];
    $text = jeab_gp_event_description($sample);
    det_ok(str_contains($text, $phrase) && str_contains($text, 'Provgatan 1') && str_contains($text, 'prov@example.test'), $job . ' har adress och hela texten');
}

$liveRow = $store->getBooking((int) $live['id']);
$liveBody = jeab_gp_event_body(is_array($liveRow) ? $liveRow : $live);
$liveDesc = (string) ($liveBody['body']['content'] ?? '');
det_ok((string) ($liveBody['location']['displayName'] ?? '') === 'Batterivägen 2', 'batteri utan order använder bokningens adress');
det_ok(str_contains($liveDesc, 'sätter upp ditt batteri'), 'batteriets hela text följer med');

jeab_outlook_event_save($bookingId, 'Niman', 'saljare5@jarfalla.example.test', 'evt-sol-niman', 'busy');
jeab_outlook_event_save($bookingId, 'Elin', 'saljare4@jarfalla.example.test', 'evt-sol-elin', 'busy');
jeab_outlook_event_save((int) $live['id'], 'Niman', 'saljare5@jarfalla.example.test', 'evt-live', 'busy');
jeab_outlook_event_save((int) $past['id'], 'Niman', 'saljare5@jarfalla.example.test', 'evt-past', 'busy');

$before = $store->getBooking($bookingId);
$beforeStart = (string) ($before['starts_at'] ?? '');
$beforeEnd = (string) ($before['ends_at'] ?? '');
$beforeUpdated = (string) ($before['updated_at'] ?? '');

$calls = [];
$dry = jeab_outlook_hook($store, ['action' => 'calendar.backfill_details']);
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
det_ok(!empty($dry['ok']) && $dry['dry'] === true && !isset($dry['written']), 'utan argument är körningen torr');
det_ok($calls === [], 'torrkörning anropar inte Graph');
$dryIds = array_map(static fn(array $item): int => (int) $item['id'], $dry['items']);
det_ok(in_array($bookingId, $dryIds, true) && in_array((int) $live['id'], $dryIds, true), 'framtida och pågående händelser listas');
det_ok(!in_array((int) $past['id'], $dryIds, true) && !in_array((int) $bare['id'], $dryIds, true), 'passerat och händelselöst hoppas över');
foreach ($dry['items'] as $item) {
    det_ok(array_keys($item) === ['id', 'date', 'events'], 'raden är id, datum och antal');
}
$solItem = null;
foreach ($dry['items'] as $item) {
    if ((int) $item['id'] === $bookingId) {
        $solItem = $item;
    }
}
det_ok(is_array($solItem) && (int) $solItem['events'] === 2, 'båda resursernas händelser räknas');
det_ok(!str_contains((string) $dryJson, '19800101') && !str_contains((string) $dryJson, '@') && !str_contains((string) $dryJson, 'Solvägen'), 'torrsvaret saknar personnummer och kundtext');

$calls = [];
$need = jeab_outlook_hook($store, ['action' => 'calendar.backfill_details', 'dry' => 0]);
det_ok(empty($need['ok']) && (int) ($need['status'] ?? 0) === 422 && $calls === [], 'dry=0 utan confirm skriver inte');

$calls = [];
$held = jeab_outlook_hook($store, ['action' => 'calendar.backfill_details', 'dry' => 1, 'confirm' => 1]);
det_ok(!empty($held['ok']) && $held['dry'] === true && $calls === [], 'dry=1 skriver inte ens med confirm');

$calls = [];
$wrote = jeab_outlook_hook($store, ['action' => 'calendar.backfill_details', 'confirm' => 1]);
$after = $store->getBooking($bookingId);
det_ok(!empty($wrote['ok']) && $wrote['dry'] === false && (int) ($wrote['written'] ?? 0) === 3 && (int) ($wrote['failed'] ?? 0) === 0, 'confirm skriver de tre framtida händelserna');
det_ok((string) ($after['starts_at'] ?? '') === $beforeStart && (string) ($after['ends_at'] ?? '') === $beforeEnd, 'tiderna är orörda');
det_ok((string) ($after['updated_at'] ?? '') === $beforeUpdated, 'bokningens klocka är orörd');
$wroteJson = json_encode($wrote, JSON_UNESCAPED_UNICODE);
det_ok(!str_contains((string) $wroteJson, '19800101') && !str_contains((string) $wroteJson, '@'), 'skrivsvaret saknar personnummer och mejl');
det_ok(count($calls) === 3, 'en PATCH per händelse');
foreach ($calls as $call) {
    det_ok($call[0] === 'PATCH', 'backfill använder PATCH');
    det_ok(str_contains($call[1], 'sendUpdates=none'), 'inbjudan skickas inte');
    det_ok(is_array($call[2]), 'PATCH har en kropp');
    $keys = array_keys($call[2]);
    sort($keys);
    det_ok($keys === ['body', 'location'], 'bara plats och brödtext');
    det_ok(!isset($call[2]['subject']) && !isset($call[2]['start']) && !isset($call[2]['end']) && !isset($call[2]['attendees']), 'rubrik, tid och deltagare saknas');
    $content = (string) ($call[2]['body']['content'] ?? '');
    det_ok($content !== '' && !str_contains($content, '19800101'), 'kroppen har text och inget personnummer');
}

$calls = [];
unset($GLOBALS['_JEAB_QUIET_BOOKING']);
$bareFresh = $store->getBooking((int) $bare['id']);
det_ok(is_array($bareFresh), 'servicebokningen finns');
$synced = jeab_gp_upsert_event('saljare4@jarfalla.example.test', $bareFresh);
det_ok($synced === true, 'ny händelse skapas');
$posted = null;
foreach ($calls as $call) {
    if ($call[0] === 'POST') {
        $posted = $call;
    }
}
det_ok(is_array($posted) && str_contains($posted[1], 'sendUpdates=none') && ($posted[2]['attendees'] ?? null) === [], 'skapandet bjuder inte in');
det_ok((string) ($posted[2]['location']['displayName'] ?? '') === 'Servicegatan 4', 'skapandet sätter platsen');
det_ok(str_contains((string) ($posted[2]['body']['content'] ?? ''), 'Bokad tid:'), 'skapandet har hela servicetexten');

$prepend = $tmp . '/prepend.php';
file_put_contents($prepend, "<?php\n\$GLOBALS['_JEAB_DATA_DIR'] = " . var_export($tmp, true) . ";\n");
$port = 8091;
$log = $tmp . '/httpd.log';
$proc = proc_open(
    ['php', '-d', 'auto_prepend_file=' . $prepend, '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__)],
    [['pipe', 'r'], ['file', $log, 'a'], ['file', $log, 'a']],
    $pipes,
    dirname(__DIR__)
);
det_ok(is_resource($proc), 'lokal hook startar');
$up = false;
for ($i = 0; $i < 40; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/hook.php');
    if (is_string($probe) && str_contains($probe, '"ok"')) {
        $up = true;
        break;
    }
    usleep(100000);
}
det_ok($up, 'hook svarar');

$curl = static function (array $payload) use ($port, $secret): array {
    $ch = curl_init('http://127.0.0.1:' . $port . '/hook.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Webhook-Token: ' . $secret,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['code' => $code, 'raw' => is_string($raw) ? $raw : ''];
};

$curlDry = $curl(['action' => 'calendar.backfill_details']);
$curlNeed = $curl(['action' => 'calendar.backfill_details', 'dry' => 0]);
det_ok($curlDry['code'] === 200 && str_contains($curlDry['raw'], '"dry":true'), 'curl torrkörning');
det_ok($curlNeed['code'] === 422 && str_contains($curlNeed['raw'], 'confirm=1'), 'curl dry=0 kräver confirm');
det_ok(!str_contains($curlDry['raw'], '19800101') && !str_contains($curlDry['raw'], '@') && !str_contains($curlNeed['raw'], '19800101'), 'curl-svaret saknar personnummer');

proc_terminate($proc);
$serverPid = (int) (proc_get_status($proc)['pid'] ?? 0);
proc_close($proc);
if ($serverPid > 0 && function_exists('posix_kill')) {
    posix_kill($serverPid, 15);
}

echo "OK  klart\n";
