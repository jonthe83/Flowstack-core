<?php

declare(strict_types=1);

/**
 * Bolaget följer ansvarig.
 * Mottagaren bestämmer vid ankomst. Tilldelning till ett annat bolag flyttar ärende och påminnelse.
 * Kroken company.realign_by_assignee är torr som standard. Inget skickas.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-company-realign-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_DATA_BACKEND'] = 'mysql';
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
$_ENV['DATA_BACKEND'] = 'mysql';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require $root . '/src/bootstrap.php';
require_once $root . '/src/company_realign.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function cr(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pnr = '198001011234';
$store = jeab_store();
cr($store instanceof JeabPdoStore, 'sqlite-lager');
$pdo = $store->pdo();
$pdo->exec('PRAGMA busy_timeout = 8000');

$fresh = static function (): void {
    unset(
        $GLOBALS['_JEAB_INKORG_THREADS'],
        $GLOBALS['_JEAB_INKORG_THREADS_FP'],
        $GLOBALS['_JEAB_INKORG_META'],
        $GLOBALS['_JEAB_INKORG_META_FP'],
        $GLOBALS['_JEAB_INKORG_LIGHT']
    );
};

$listCompany = static function (string $id) use (&$pdo, $fresh): string {
    $fresh();
    $stmt = $pdo->prepare('SELECT company FROM inkorg_list WHERE id = ?');
    $stmt->execute([$id]);

    return jeab_company_realign_norm((string) $stmt->fetchColumn());
};
$threadStored = static function (string $id) use (&$pdo, $fresh): array {
    $fresh();
    $stmt = $pdo->prepare('SELECT row_json FROM inkorg_threads WHERE id = ?');
    $stmt->execute([$id]);
    $json = json_decode((string) $stmt->fetchColumn(), true);

    return is_array($json) ? $json : [];
};
$shown = static function (string $id) use ($fresh): string {
    $fresh();

    return jeab_company_realign_norm(jeab_inkorg_thread_company_of($id));
};

$ingest = static function (string $id, string $subject, string $from, string $body, array $labels = []) use ($root): array {
    unset($root);
    $mail = [
        'message_id' => '<' . $id . '@company-realign.test>',
        'from' => $from,
        'from_name' => 'Hitta-Solceller',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => $subject,
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'received_at' => '2026-10-28T09:00:00+01:00',
        'test' => true,
    ];
    if ($labels !== []) {
        $mail['preset_labels'] = $labels;
    }
    $out = jeab_inkorg_ingest($mail);
    cr(!empty($out['ok']), 'inkorg tar in ' . $id . ' ' . json_encode($out));

    return $out;
};

$address = "Namn: Test Kund\nE-post: kund.jarfalla@example.test\nTelefon: 0701112233\nAdress: Mälarvägen 12\nPostnummer: 177 31\nOrt: Järfälla\n";
$arrived = $ingest(
    'arrival',
    'Hitta-Solceller.se - Ny offert - Demo Energi Malmö',
    'lead@hitta-solceller.se',
    $address,
    ['type' => 'Ny lead']
);
$arrivalId = (string) $arrived['thread_id'];
cr($listCompany($arrivalId) === 'jarfalla' && $shown($arrivalId) === 'jarfalla', 'ankomst till kontakt@jarfalla.example.test stannar i Järfälla trots Malmö i ämnet');
cr((string) ($threadStored($arrivalId)['assignee'] ?? '') === '', 'ankomst sätter ingen ansvarig');

jeab_inkorg_assign($arrivalId, 'Elin', true);
cr($listCompany($arrivalId) === 'jarfalla' && $shown($arrivalId) === 'jarfalla', 'Elin tillhör Järfälla och ärendet stannar');

$case = $ingest(
    'case',
    'Hitta-Solceller.se - Ny offert - Demo Energi Malmö',
    'lead2@hitta-solceller.se',
    $address,
    ['type' => 'Ny lead']
);
$caseId = (string) $case['thread_id'];
$quote = ['at' => '2026-10-28T10:00:00+01:00', 'kind' => 'offert', 'product' => 'solceller', 'snapshot' => 'skickad offert'];
jeab_inkorg_thread_write($caseId, static function (array $t) use ($quote): array {
    $t['assignee'] = 'SamW';
    $t['quote_sent'] = $quote;
    $lead = is_array($t['lead_customer'] ?? null) ? $t['lead_customer'] : [];
    $lead['email'] = 'kund.jarfalla@example.test';
    $lead['phone'] = '0701112233';
    $lead['address'] = 'Mälarvägen 12, Järfälla';
    $t['lead_customer'] = $lead;

    return $t;
});
cr($shown($caseId) === 'jarfalla' && $listCompany($caseId) === 'jarfalla', 'ansvarig Sam W utan bolagsbyte lämnar mejlboxens bolag');
cr(($threadStored($caseId)['quote_sent']['snapshot'] ?? '') === 'skickad offert', 'offertkopia finns före flytt');

$plain = $ingest('plain', 'Hej från kunden', 'kund.plain@example.test', "Hej, jag har en fråga om batteri.\n");
$plainId = (string) $plain['thread_id'];
jeab_inkorg_thread_write($plainId, static function (array $t): array {
    $t['assignee'] = 'SamW';

    return $t;
});
cr($shown($plainId) === 'jarfalla', 'vanligt mejl till Järfälla stannar i Järfälla');
cr(!jeab_company_row_is_case($threadStored($plainId)), 'vanligt mejl är inget ärende i räkningen');

$contacts = jeab_kontakter_pdo_store();
cr($contacts instanceof JeabPdoContactStore, 'kontakter ligger i SQL');
$contacts->upsert('kt-realign-a', [
    'id' => 'kt-realign-a',
    'name' => 'Kund A',
    'email' => 'samma@example.test',
    'company' => 'jarfalla',
]);
$contacts->upsert('kt-realign-b', [
    'id' => 'kt-realign-b',
    'name' => 'Kund B',
    'email' => 'samma@example.test',
    'company' => 'jarfalla',
]);
jeab_inkorg_thread_write($caseId, static function (array $t): array {
    $t['contact_id'] = 'kt-realign-a';

    return $t;
});

$now = jeab_now();
$linked = $store->insertReminder([
    'title' => 'Påminnelse till Sam W',
    'company' => 'malmo',
    'assignee' => 'Sam W',
    'assignees' => ['Sam W'],
    'person' => 'SamW',
    'note' => 'internt ' . $pnr,
    'thread_id' => $caseId,
    'created_at' => $now,
    'updated_at' => $now,
]);
$linkedId = (int) $linked['id'];
$customer = $store->insertCustomer('Bokad kund', $now);
$booking = $store->insertBooking([
    'customer_id' => (int) $customer['id'],
    'customer_name' => 'Bokad kund',
    'email' => 'kund.jarfalla@example.test',
    'assignee' => 'Elin',
    'company' => 'jarfalla',
    'starts_at' => '2026-11-02 09:00:00',
    'ends_at' => '2026-11-02 11:00:00',
    'status' => 'booked',
    'contact_id' => 'kt-realign-a',
    'created_at' => $now,
    'updated_at' => $now,
]);
$bookingId = (int) $booking['id'];
$pdo->prepare('UPDATE reminders SET company = ?, contact_id = ?, thread_id = ?, booking_id = ? WHERE id = ?')
    ->execute(['jarfalla', 'kt-realign-a', $caseId, $bookingId, $linkedId]);
$purchasePayload = json_encode(['company' => 'jarfalla', 'kind' => 'offert', 'sent' => true, 'marker' => $pnr], JSON_UNESCAPED_UNICODE);
$pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at) VALUES (?, ?, ?)')->execute([$linkedId, $purchasePayload, $now]);

$whoRow = $store->insertReminder([
    'title' => 'Vem',
    'company' => 'jarfalla',
    'assignee' => 'Elin',
    'assignees' => ['Elin'],
    'person' => 'Elin',
    'created_at' => $now,
    'updated_at' => $now,
]);
$whoHtml = jeab_reminder_who_html($whoRow, '/');
cr(str_contains($whoHtml, 'Sam W') && str_contains($whoHtml, 'value="jarfalla"'), 'Påminnelser visar Sam W och postar radens kontor');
$movedWho = jeab_update_reminder($store, (int) $whoRow['id'], [
    'assignee' => 'Sam W',
    'who' => 'Sam W',
    'company' => 'jarfalla',
]);
cr(jeab_reminder_company($movedWho) === 'malmo' && (string) ($movedWho['person'] ?? '') === 'SamW', 'reminder.who till Sam W följer Malmö');

$followMail = $ingest('follow', 'Fråga om offert', 'kund.follow@example.test', "Hej.\nAdress: Veddestavägen 1, Järfälla\n");
$followId = (string) $followMail['thread_id'];
$followRem = $store->insertReminder([
    'title' => 'Kopplad',
    'company' => 'jarfalla',
    'assignee' => 'Elin',
    'assignees' => ['Elin'],
    'person' => 'Elin',
    'thread_id' => $followId,
    'created_at' => $now,
    'updated_at' => $now,
]);
$followRemId = (int) $followRem['id'];
$contacts->upsert('kt-realign-c', [
    'id' => 'kt-realign-c',
    'name' => 'Kund C',
    'email' => 'kund.follow@example.test',
    'company' => 'jarfalla',
]);
$pdo->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute(['kt-realign-c', $followRemId]);
jeab_inkorg_thread_write($followId, static function (array $t): array {
    $t['contact_id'] = 'kt-realign-c';

    return $t;
});
$followed = jeab_update_reminder($store, $followRemId, [
    'assignee' => 'Sam W',
    'who' => 'Sam W',
    'company' => 'jarfalla',
]);
cr(jeab_reminder_company($followed) === 'malmo', 'kopplad påminnelse följer Sam W');
cr($shown($followId) === 'malmo' && $listCompany($followId) === 'malmo', 'tomt ärende följer påminnelsens nya ansvarig');
cr((string) ($threadStored($followId)['assignee'] ?? '') === 'SamW', 'tomt ärende får samma person');
cr(jeab_company_contact_company('kt-realign-c') === 'malmo', 'kopplad kontakt följer utan att slås ihop');

$assignMail = $ingest(
    'assign',
    'Hitta-Solceller.se - Ny offert - Demo Energi Malmö',
    'lead3@hitta-solceller.se',
    $address,
    ['type' => 'Ny lead']
);
$assignId = (string) $assignMail['thread_id'];
$contacts->upsert('kt-realign-d', [
    'id' => 'kt-realign-d',
    'name' => 'Kund D',
    'email' => 'samma@example.test',
    'company' => 'jarfalla',
]);
jeab_inkorg_thread_write($assignId, static function (array $t): array {
    $t['contact_id'] = 'kt-realign-d';
    $lead = is_array($t['lead_customer'] ?? null) ? $t['lead_customer'] : [];
    $lead['email'] = 'annan.kund@example.test';
    $lead['phone'] = '0709998877';
    $t['lead_customer'] = $lead;

    return $t;
});
$assignRem = $store->insertReminder([
    'title' => 'Ärende',
    'company' => 'malmo',
    'assignee' => 'Sam W',
    'assignees' => ['Sam W'],
    'person' => 'SamW',
    'thread_id' => $assignId,
    'created_at' => $now,
    'updated_at' => $now,
]);
$assignRemId = (int) $assignRem['id'];
$pdo->prepare('UPDATE reminders SET company = ?, contact_id = ?, thread_id = ? WHERE id = ?')
    ->execute(['jarfalla', 'kt-realign-d', $assignId, $assignRemId]);
jeab_inkorg_assign($assignId, 'SamW', true);
cr($listCompany($assignId) === 'malmo' && $shown($assignId) === 'malmo', 'jeab_inkorg_assign till Sam W flyttar ärendet till Malmö');
cr((string) ($threadStored($assignId)['company'] ?? '') === 'malmo', 'trådens bolag sparas med ansvarig');
$assignRemRow = $pdo->prepare('SELECT company, contact_id, thread_id, assignee FROM reminders WHERE id = ?');
$assignRemRow->execute([$assignRemId]);
$assignRemDb = $assignRemRow->fetch(PDO::FETCH_ASSOC);
cr(is_array($assignRemDb) && (string) $assignRemDb['company'] === 'malmo', 'kopplad påminnelse flyttar med ärendet');
cr((string) ($assignRemDb['contact_id'] ?? '') === 'kt-realign-d' && (string) ($assignRemDb['thread_id'] ?? '') === $assignId, 'påminnelsens kontakt och tråd består');
cr(jeab_company_contact_company('kt-realign-d') === 'malmo', 'ärendets kontakt får Malmö');
cr(jeab_company_contact_company('kt-realign-b') === 'jarfalla', 'annan kontakt med samma e-post slås inte ihop');
$contactIds = static function () use (&$pdo): array {
    $rows = $pdo->query('SELECT id FROM contacts ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

    return array_map('strval', is_array($rows) ? $rows : []);
};
$contactsBefore = $contactIds();
cr(in_array('kt-realign-a', $contactsBefore, true) && in_array('kt-realign-b', $contactsBefore, true) && in_array('kt-realign-d', $contactsBefore, true), 'kontakterna ligger kvar som egna rader');

$beforeMessages = $pdo->query('SELECT id, thread_id, row_json FROM inkorg_messages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$beforeBookings = (int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
$beforeReminders = (int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn();
$beforePurchases = (int) $pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn();
$quoteBefore = $threadStored($caseId)['quote_sent'] ?? null;
$bookingBefore = $pdo->query('SELECT id, company, contact_id, status FROM bookings')->fetchAll(PDO::FETCH_ASSOC);

$dry = jeab_company_realign(['action' => 'company.realign_by_assignee']);
cr(($dry['ok'] ?? false) === true && ($dry['dry'] ?? 0) === 1 && ($dry['sent'] ?? 1) === 0 && ($dry['would_send'] ?? true) === false, 'kroken är torr som standard och skickar inget');
$tables = is_array($dry['tables'] ?? null) ? $dry['tables'] : [];
cr((int) ($tables['inkorg_list']['count'] ?? -1) === 2, 'torr körning räknar två listrader');
cr((int) ($tables['inkorg_threads']['count'] ?? -1) === 2, 'torr körning räknar två trådar');
cr((int) ($tables['inkorg_cases']['count'] ?? -1) === 1 && in_array($caseId, (array) ($tables['inkorg_cases']['ids'] ?? []), true), 'bara leadet räknas som ärende');
cr(!in_array($plainId, (array) ($tables['inkorg_cases']['ids'] ?? []), true), 'vanligt mejl räknas inte som ärende');
cr((int) ($tables['reminders']['count'] ?? -1) === 1 && in_array((string) $linkedId, array_map('strval', (array) ($tables['reminders']['ids'] ?? [])), true), 'en påminnelse skiljer sig');
cr((int) (($dry['contacts']['count'] ?? -1)) === 1 && in_array('kt-realign-a', (array) ($dry['contacts']['ids'] ?? []), true), 'en kontakt skiljer sig');
cr($listCompany($caseId) === 'jarfalla', 'torr körning skriver inte');
cr(!str_contains(json_encode($dry), $pnr), 'svaret innehåller inte personnummer');
cr(jeab_company_realign_scrub('rad-' . $pnr) === 'rad-', 'personnummer tas bort ur id');

$denied = jeab_company_realign_dispatch(['action' => 'company.realign_by_assignee']);
cr(($denied['ok'] ?? true) === false && (int) ($denied['status'] ?? 0) === 401, 'utan token blir det 401');

$inboxToken = 'ink_' . bin2hex(random_bytes(8));
$tokenPath = jeab_inkorg_token_path();
$dirOk = is_dir(dirname($tokenPath)) || mkdir(dirname($tokenPath), 0775, true);
$wrote = file_put_contents($tokenPath, json_encode(['key' => $inboxToken]));
cr($dirOk && $wrote !== false && is_file($tokenPath), 'tokenfil ' . $tokenPath);

$httpRoot = sys_get_temp_dir() . '/jeab-company-realign-http-' . bin2hex(random_bytes(4));
$httpEmpty = $httpRoot . '/empty';
mkdir($httpEmpty, 0775, true);
$router = $httpRoot . '/router.php';
$log = $httpRoot . '/php.log';
file_put_contents($router, <<<'PHP'
<?php
$data = getenv('JEAB_CR_DATA');
$root = getenv('JEAB_CR_ROOT');
if (!is_string($data) || $data === '' || !is_string($root) || $root === '') {
    http_response_code(500);
    echo 'missing';
    return true;
}
$GLOBALS['_JEAB_DATA_DIR'] = $data;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $data . '/public';
$GLOBALS['_JEAB_DATA_BACKEND'] = 'mysql';
$GLOBALS['_JEAB_RAW_BODY'] = file_get_contents('php://input') ?: '';
if (function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', (string) $name));
        if (!isset($_SERVER[$key]) || (string) $_SERVER[$key] === '') {
            $_SERVER[$key] = $value;
        }
    }
}
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
cr($probe !== false, 'kunde inte välja port');
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
$cmd = 'JEAB_DISABLE_SQLITE=0 DATA_BACKEND=mysql JEAB_CR_DATA=' . escapeshellarg($tmp)
    . ' JEAB_CR_ROOT=' . escapeshellarg($root)
    . ' ' . escapeshellarg(PHP_BINARY)
    . ' -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($log)
    . ' -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($httpEmpty)
    . ' ' . escapeshellarg($router);
$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
cr(is_resource($proc), 'lokal PHP-server startade');
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
cr($up, 'testservern lyssnar');

$http = static function (array $payload, bool $auth) use ($port, $inboxToken): array {
    if ($auth) {
        $payload['token'] = $inboxToken;
    }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers = ['Content-Type: application/json'];
    if ($auth) {
        $headers[] = 'Authorization: Bearer ' . $inboxToken;
        $headers[] = 'X-Webhook-Token: ' . $inboxToken;
    }
    $ch = curl_init('http://127.0.0.1:' . $port . '/hook.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $text = is_string($raw) ? $raw : '';
    $json = json_decode($text, true);

    return ['status' => $status, 'json' => is_array($json) ? $json : [], 'body' => $text];
};

$httpDenied = $http(['action' => 'company.realign_by_assignee'], false);
cr($httpDenied['status'] === 401, 'hook.php utan token är 401');
$httpDry = $http(['action' => 'company.realign_by_assignee'], true);
cr($httpDry['status'] === 200 && ($httpDry['json']['dry'] ?? 0) === 1 && ($httpDry['json']['sent'] ?? 1) === 0 && ($httpDry['json']['would_send'] ?? true) === false, 'curl torr körning');
cr((int) ($httpDry['json']['tables']['inkorg_list']['count'] ?? -1) === 2, 'curl räknar listrader');
cr((int) ($httpDry['json']['tables']['inkorg_cases']['count'] ?? -1) === 1, 'curl räknar ärenden');
cr(!str_contains($httpDry['body'], $pnr) && !str_contains($httpDry['body'], $inboxToken), 'curl-svaret har varken personnummer eller token');
cr($listCompany($caseId) === 'jarfalla', 'curl torr körning lämnar Järfälla');

$release = static function () use ($store, &$pdo): void {
    if (function_exists('jeab_pdo_in_tx') && jeab_pdo_in_tx($pdo)) {
        jeab_pdo_end($pdo, true);
    } elseif ($pdo->inTransaction()) {
        $pdo->commit();
    }
    try {
        $pdo->exec('ROLLBACK');
    } catch (Throwable) {
    }
    if (function_exists('jeab_pdo_replace')) {
        jeab_pdo_replace($pdo);
        $pdo = $store->pdo();
    }
};
$appliedHttp = $http(['action' => 'company.realign_by_assignee', 'dry' => 0], true);
$release();
cr($appliedHttp['status'] === 200, 'curl verklig körning ' . $appliedHttp['status'] . ' ' . substr($appliedHttp['body'], 0, 300));
$applied = $appliedHttp['json'];
cr(($applied['ok'] ?? false) === true && ($applied['dry'] ?? 1) === 0 && preg_match('/^[a-f0-9]{16}$/', (string) ($applied['undo_id'] ?? '')) === 1, 'verklig körning ger en ångringsnyckel');
cr(($applied['sent'] ?? 1) === 0 && ($applied['would_send'] ?? true) === false && ($applied['deleted'] ?? 1) === 0, 'verklig körning skickar och raderar inget');
cr($listCompany($caseId) === 'malmo' && $shown($caseId) === 'malmo', 'ärendet flyttar till Malmö');
cr($listCompany($plainId) === 'malmo' && $shown($plainId) === 'malmo', 'vanliga mejlet flyttar till Malmö');
cr((string) ($threadStored($caseId)['company'] ?? '') === 'malmo', 'trådjson får Malmö');
cr(($threadStored($caseId)['quote_sent'] ?? null) == $quoteBefore, 'skickad offert lämnas orörd');
$linkedAfter = $pdo->prepare('SELECT company, contact_id, thread_id, booking_id, assignee FROM reminders WHERE id = ?');
$linkedAfter->execute([$linkedId]);
$linkedDb = $linkedAfter->fetch(PDO::FETCH_ASSOC);
cr(is_array($linkedDb) && (string) $linkedDb['company'] === 'malmo', 'påminnelsen flyttar till Malmö');
cr((string) ($linkedDb['contact_id'] ?? '') === 'kt-realign-a' && (string) ($linkedDb['thread_id'] ?? '') === $caseId && (int) ($linkedDb['booking_id'] ?? 0) === $bookingId, 'påminnelsens länkar består');
cr((string) ($linkedDb['assignee'] ?? '') === 'Sam W', 'ansvarig text består');
cr(jeab_company_contact_company('kt-realign-a') === 'malmo', 'kontakten följer med');
cr(jeab_company_contact_company('kt-realign-b') === 'jarfalla', 'tvillingkontakten består');
cr((int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn() === $beforeReminders, 'inga påminnelser försvinner');
cr((int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn() === $beforeBookings, 'inga bokningar försvinner');
cr((int) $pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn() === $beforePurchases, 'inga ordrar försvinner');
cr($pdo->query('SELECT id, company, contact_id, status FROM bookings')->fetchAll(PDO::FETCH_ASSOC) == $bookingBefore, 'bokningen är orörd');
cr((string) $pdo->query('SELECT payload FROM purchases WHERE reminder_id = ' . $linkedId)->fetchColumn() === $purchasePayload, 'orderns kopia är orörd');
cr($pdo->query('SELECT id, thread_id, row_json FROM inkorg_messages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) == $beforeMessages, 'mejlen är orörda');
cr(!str_contains(json_encode($applied), $pnr), 'verkligt svar innehåller inte personnummer');
$remain = is_array($applied['remaining'] ?? null) ? $applied['remaining'] : [];
cr((int) ($remain['inkorg_list']['count'] ?? -1) === 0 && (int) ($remain['reminders']['count'] ?? -1) === 0, 'inget återstår efter flytten');

$undoId = (string) $applied['undo_id'];
$undoDry = $http(['action' => 'company.realign_by_assignee_undo', 'undo_id' => $undoId], true);
$release();
cr($undoDry['status'] === 200 && ($undoDry['json']['dry'] ?? 0) === 1 && ($undoDry['json']['sent'] ?? 1) === 0, 'ångring är torr som standard');
cr((int) ($undoDry['json']['tables']['inkorg_list']['count'] ?? -1) === 2, 'ångring räknar listrader');
cr((int) ($undoDry['json']['tables']['inkorg_cases']['count'] ?? -1) === 1, 'ångring räknar bara ärendet');
cr(!in_array($plainId, (array) ($undoDry['json']['tables']['inkorg_cases']['ids'] ?? []), true), 'ångring räknar inte vanligt mejl som ärende');
cr($listCompany($caseId) === 'malmo', 'torr ångring skriver inte');

$restored = $http(['action' => 'company.realign_by_assignee_undo', 'undo_id' => $undoId, 'dry' => 0], true);
$release();
cr($restored['status'] === 200 && ($restored['json']['restored'] ?? 0) === 1 && ($restored['json']['sent'] ?? 1) === 0 && ($restored['json']['would_send'] ?? true) === false, 'curl ångrar flytten');
cr($listCompany($caseId) === 'jarfalla' && $shown($caseId) === 'jarfalla', 'ärendet är tillbaka i Järfälla');
cr($listCompany($plainId) === 'jarfalla', 'vanliga mejlet är tillbaka');
cr((string) ($threadStored($caseId)['assignee'] ?? '') === 'SamW', 'ansvarig består efter ångring');
cr(($threadStored($caseId)['quote_sent']['snapshot'] ?? '') === 'skickad offert', 'offertkopia består efter ångring');
$linkedBack = $pdo->prepare('SELECT company, contact_id, thread_id, booking_id FROM reminders WHERE id = ?');
$linkedBack->execute([$linkedId]);
$linkedBackDb = $linkedBack->fetch(PDO::FETCH_ASSOC);
cr(is_array($linkedBackDb) && (string) $linkedBackDb['company'] === 'jarfalla', 'påminnelsen är tillbaka');
cr((string) ($linkedBackDb['contact_id'] ?? '') === 'kt-realign-a' && (string) ($linkedBackDb['thread_id'] ?? '') === $caseId && (int) ($linkedBackDb['booking_id'] ?? 0) === $bookingId, 'länkarna består efter ångring');
cr(jeab_company_contact_company('kt-realign-a') === 'jarfalla', 'kontakten är tillbaka');
cr($contactIds() === $contactsBefore, 'kontakterna slogs inte ihop');
cr((string) $pdo->query('SELECT payload FROM purchases WHERE reminder_id = ' . $linkedId)->fetchColumn() === $purchasePayload, 'orderkopian består');
cr(!str_contains($restored['body'], $pnr), 'ångringssvaret innehåller inte personnummer');

echo "OK  company follows assignee\n";
