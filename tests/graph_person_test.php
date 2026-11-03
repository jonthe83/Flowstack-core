<?php

declare(strict_types=1);

/** Personavsändare/kalender via Graph: flaggor av = ingen ändring, på = rätt brevlåda per produkt. */

$tmp = sys_get_temp_dir() . '/jeab-gp-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export(['timezone' => 'Europe/Stockholm', 'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json']], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ok(bool $c, string $m): void
{
    if (!$c) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$calls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body = null, array $headers = []) use (&$calls) {
    unset($headers);
    $calls[] = [$method, $url, $body];
    if (str_ends_with($url, '/sendMail')) {
        return ['code' => 202, 'body' => []];
    }
    if (str_contains($url, '/mailFolders/sentitems/messages')) {
        $last = null;
        foreach ($calls as $c) {
            if (str_ends_with($c[1], '/sendMail')) {
                $last = $c;
            }
        }
        $ref = $last[2]['message']['internetMessageHeaders'][0]['value'] ?? '';
        return ['code' => 200, 'body' => ['value' => [['id' => 'S1', 'conversationId' => 'C1', 'internetMessageId' => '<m@x>', 'internetMessageHeaders' => [['name' => 'X-JEAB-Ref', 'value' => $ref]]]]]];
    }
    if (str_contains($url, '/calendarView')) {
        return ['code' => 200, 'body' => ['value' => [
            ['id' => 'O1', 'subject' => 'Möte', 'start' => ['dateTime' => '2026-10-20T08:00:00'], 'end' => ['dateTime' => '2026-10-20T10:00:00'], 'showAs' => 'busy'],
            ['start' => ['dateTime' => '2026-10-20T12:00:00'], 'end' => ['dateTime' => '2026-10-20T13:00:00'], 'showAs' => 'free'],
        ]]];
    }
    if ($method === 'POST' && (str_ends_with($url, '/events') || str_contains($url, '/events?'))) {
        return ['code' => 201, 'body' => ['id' => 'E' . count($calls)]];
    }
    if ($method === 'POST' && str_ends_with($url, '/messages')) {
        return ['code' => 201, 'body' => ['id' => 'D1']];
    }
    if ($method === 'DELETE') {
        return ['code' => 204, 'body' => []];
    }
    return ['code' => 200, 'body' => ['id' => 'x']];
};

// Ägare per produkt (bara Järfälla).
ok(jeab_gp_owner_email('batteri', 'jarfalla') === 'saljare3@jarfalla.example.test', 'Batteri → Roberto');
ok(jeab_gp_owner_email('laddbox', 'jarfalla') === 'saljare4@jarfalla.example.test', 'Elbilsladdare → Elin');
ok(jeab_gp_owner_email('sol', 'jarfalla') === 'demo@example.test', 'Sol → Sam L');
ok(jeab_gp_owner_email('batteri', 'malmo') === '', 'Malmö: ingen personavsändare (Help Scout)');
ok(count(jeab_gp_staff_emails()) >= 8, 'alla anställda i båda bolagen');
ok(jeab_gp_person_email('Kim', 'malmo') === 'saljare.syd@malmo.example.test', 'Kim hittas');
ok(jeab_gp_person_email('evil@ex.se') === '', 'okänd adress nekas');

// Flaggor av: ingen Graph-personväg.
$GLOBALS['_JEAB_GP_FLAGS'] = ['graph_person_mail' => false, 'graph_person_calendar' => false];
ok(!jeab_gp_mail_on() && !jeab_gp_calendar_on(), 'flaggor av som standard');

// Mejl på.
$GLOBALS['_JEAB_GP_FLAGS']['graph_person_mail'] = true;
// jeab_graph_configured kräver m365.json
@mkdir($tmp . '/secrets', 0700, true);
file_put_contents($tmp . '/secrets/m365.json', json_encode(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']));
ok(jeab_gp_mail_on(), 'mejl på');
$calls = [];
$r = jeab_outbound_graph_send('jarfalla', 'kund@ex.se', 'Offert', '<p>Hej</p>', true, [['filename' => 'o.pdf', 'content_type' => 'application/pdf', 'content' => '%PDF-1']], [], ['person_email' => 'saljare3@jarfalla.example.test', 'reply_to_graph_id' => 'G1']);
ok(!empty($r['ok']) && $r['from'] === 'saljare3@jarfalla.example.test' && !empty($r['in_sent_items']), 'offert från Robertos brevlåda, i Skickat');
ok(str_contains($calls[0][1], 'users/roberto%40jarfalla.example.test/sendMail'), 'sendMail på Roberto');
ok(($calls[0][2]['saveToSentItems'] ?? false) === true && count($calls[0][2]['message']['attachments']) === 1, 'PDF med, sparas i Skickat');

// Utan person_email: kontakt@ som förut.
$calls = [];
jeab_outbound_graph_send('jarfalla', 'kund@ex.se', 'X', 'Y', false, [], [], []);
ok(str_contains($calls[0][1], 'kontakt%40jarfalla.example.test'), 'utan person: kontakt@');

// Personens skicka och utkast går via Graph.
$calls = [];
$s = jeab_gp_send('saljare4@jarfalla.example.test', 'kund@ex.se', 'Offert', '<p>Hej</p>');
ok(!empty($s['ok']) && $s['from'] === 'saljare4@jarfalla.example.test' && str_contains($calls[0][1], 'elin%40jarfalla.example.test/sendMail'), 'Graph send från Elin');
$calls = [];
$d = jeab_gp_create_draft('saljare3@jarfalla.example.test', 'kund@ex.se', 'Offert', '<p>Hej</p>');
ok(!empty($d['ok']) && $calls[0][0] === 'POST' && str_ends_with($calls[0][1], 'roberto%40jarfalla.example.test/messages'), 'utkast i Robertos Utkast');

// Kalender.
$GLOBALS['_JEAB_GP_FLAGS']['graph_person_calendar'] = true;
$busy = jeab_gp_busy_for_person('Roberto', new DateTimeImmutable('2026-10-20 00:00'), new DateTimeImmutable('2026-10-21 00:00'), 'jarfalla');
ok(count($busy) === 1 && $busy[0]['starts_at'] === '2026-10-20 10:00:00', 'busy från calendarView (UTC→Stockholm, free ignoreras)');
$outlookPdo = new PDO('sqlite::memory:');
$outlookPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_OUTLOOK_PDO'] = $outlookPdo;
$calls = [];
$row = ['id' => 77, 'starts_at' => '2026-10-22 08:00:00', 'ends_at' => '2026-10-22 12:00:00', 'status' => 'booked', 'company' => 'jarfalla', 'assignees' => ['Roberto'], 'title' => 'Test', 'address' => 'Testgatan 1', 'email' => 'kund@ex.se'];
jeab_gp_sync_booking($row + ['assigned_to' => 'Roberto']);
$stored = $outlookPdo->query('SELECT email, graph_event_id FROM booking_outlook_events')->fetchAll();
ok(count($stored) === 1 && $stored[0]['email'] === 'saljare3@jarfalla.example.test' && $stored[0]['graph_event_id'] !== '', 'bokning → händelse-id i SQL');
ok(!is_file($tmp . '/graph-person-events.json'), 'event-id skrivs inte till JSON');
$posted = null;
foreach ($calls as $call) {
    if ($call[0] === 'POST' && str_contains($call[1], '/events')) {
        $posted = $call[2];
    }
}
ok(is_array($posted) && ($posted['attendees'] ?? null) === [] && !str_contains(json_encode($posted['attendees']), 'kund@ex.se'), 'kunden bjuds inte in');
jeab_gp_sync_booking(['status' => 'cancelled'] + $row);
$stored = $outlookPdo->query('SELECT graph_event_id FROM booking_outlook_events')->fetchAll();
ok($stored === [], 'avbokad → händelse borttagen');
unset($GLOBALS['_JEAB_OUTLOOK_PDO']);

// Kalender-vyn: Outlook-händelser via Graph, egna bokningshändelser hoppas över.
jeab_remember_company('jarfalla');
$wk = jeab_gp_week_events(new DateTimeImmutable('2026-10-19 00:00'), 'Roberto', null);
ok(count($wk) >= 1 && $wk[0]['who'] === 'Roberto' && $wk[0]['starts_at'] === '2026-10-20 10:00:00', 'veckovy: Robertos Outlook-händelse');

$src = (string) file_get_contents(dirname(__DIR__) . '/src/pages/bookings.php');
ok(!str_contains($src, 'jeab_nylas_connect_html') && !str_contains(strtolower($src), 'nylas'), 'koppla kalender borta från Kalender');
ok(!is_file(dirname(__DIR__) . '/src/nylas.php'), 'nylas.php är borta');

$logDir = $tmp . '/fellogg';
mkdir($logDir, 0775, true);
$GLOBALS['_JEAB_FELLOGG_DIR'] = $logDir;
$logPath = jeab_app_errors_path();
@unlink($logPath);
ok(!jeab_gp_graph_failed(['code' => 0, 'body' => ['error' => ['message' => 'Graph ej konfigurerat.']]]), 'okonfigurerat är inget Graph-fel');
ok(!jeab_gp_graph_failed(['code' => 0, 'blocked' => true, 'body' => ['error' => ['message' => 'sidan']]]), 'blockerad sidvisning loggas inte');
jeab_gp_log_graph('händelse', ['code' => 0, 'body' => ['error' => ['message' => 'Graph ej konfigurerat.']]], 77);
jeab_gp_log_graph('vecka', ['code' => 0, 'blocked' => true]);
ok(!is_file($logPath), 'tysta Graph-svar skriver ingen fellogg');

$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body = null, array $headers = []) {
    unset($body, $headers);
    if ($method === 'POST' && str_contains($url, '/events')) {
        return ['code' => 400, 'body' => ['error' => ['message' => 'kunde inte nå kund@example.test']]];
    }
    if (str_contains($url, '/calendarView')) {
        return ['code' => 503, 'body' => ['error' => ['message' => 'kalender nere']]];
    }
    return ['code' => 200, 'body' => []];
};
jeab_gp_sync_booking($row);
$logged = (string) @file_get_contents($logPath);
ok(str_contains($logged, 'Outlook händelse graph 400') && str_contains($logged, '[mejl]') && !str_contains($logged, 'kund@example.test'), 'misslyckad händelse loggas utan kundens adress');
jeab_gp_week_events(new DateTimeImmutable('2026-10-19 00:00'), 'Roberto', null);
$logged = (string) @file_get_contents($logPath);
ok(str_contains($logged, 'Outlook vecka graph 503'), 'veckovy loggar Graph-fel istället för att hoppa tyst');

echo "Alla graph_person-tester gick igenom.\n";
