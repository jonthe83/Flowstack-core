<?php

declare(strict_types=1);

/**
 * Live-formerna från Järfälla v43. Debuggen läser, skriver inte, och
 * säger varför en Outlook-rad inte blir en bokningsknapp.
 */

$tmp = sys_get_temp_dir() . '/jeab-cal-debug-' . bin2hex(random_bytes(4));
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
date_default_timezone_set('Europe/Stockholm');

function dbg_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function dbg_insert(JeabStore $store, string $name, array $data): array
{
    $stamp = jeab_now();
    $customer = $store->insertCustomer($name . ' ' . bin2hex(random_bytes(2)), $stamp);
    $data['customer_id'] = $customer['id'];
    $data['created_at'] = $stamp;
    $data['updated_at'] = $stamp;
    if (!array_key_exists('status', $data)) {
        $data['status'] = 'booked';
    }

    return $store->insertBooking($data);
}

function dbg_sql(JeabStore $store, string $sql, array $params): void
{
    $store->pdo()->prepare($sql)->execute($params);
}

function dbg_event(array $payload, string $who, string $title): ?array
{
    foreach ($payload['events'] as $event) {
        if (!is_array($event)) {
            continue;
        }
        if (($event['who'] ?? '') === $who && ($event['title'] ?? '') === $title) {
            return $event;
        }
    }

    return null;
}

function dbg_candidate(array $event, int $id): ?array
{
    foreach ($event['candidates'] as $row) {
        if (is_array($row) && (int) ($row['id'] ?? 0) === $id) {
            return $row;
        }
    }

    return null;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';
$_SESSION['jeab_company'] = 'jarfalla';
$_GET = ['p' => 'bookings', 'co' => 'jarfalla', 'who' => 'all', 'job' => 'all'];
$_SERVER['REQUEST_METHOD'] = 'GET';

$store = jeab_store(true);
$mon = '2026-10-19';
$tue = '2026-10-20';

$sten = dbg_insert($store, 'Sten Håkansson', [
    'customer_name' => 'Sten Håkansson',
    'address' => 'Huvudsväg 23, 184 90 Ljusterö',
    'notes' => '',
    'assignees' => ['Roberto'],
    'company' => 'jarfalla',
    'job_type' => 'batteri',
    'starts_at' => $mon . ' 09:00:00',
    'ends_at' => $mon . ' 11:00:00',
]);
dbg_sql($store, 'UPDATE bookings SET company = ? WHERE id = ?', ['Järfälla', (int) $sten['id']]);

$short = dbg_insert($store, 'Annan Kund', [
    'customer_name' => 'Annan Kund',
    'address' => 'Huvudsväg 23',
    'notes' => '',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => '',
    'starts_at' => $mon . ' 09:00:00',
    'ends_at' => $mon . ' 11:00:00',
]);

$kim = dbg_insert($store, 'Kim Ort', [
    'customer_name' => 'Kim Ort',
    'address' => 'Norrvägen 1, 184 90 Ljusterö',
    'notes' => '',
    'assignees' => ['Kim'],
    'company' => 'jarfalla',
    'job_type' => 'batteri',
    'starts_at' => $mon . ' 09:00:00',
    'ends_at' => $mon . ' 11:00:00',
]);

$cancelled = dbg_insert($store, 'Sten Avbokad', [
    'customer_name' => 'Sten Håkansson',
    'address' => 'Huvudsväg 23, 184 90 Ljusterö',
    'notes' => '',
    'assignees' => ['Roberto'],
    'company' => 'jarfalla',
    'job_type' => 'batteri',
    'starts_at' => $mon . ' 09:00:00',
    'ends_at' => $mon . ' 11:00:00',
]);
dbg_sql($store, 'UPDATE bookings SET status = ? WHERE id = ?', ['cancelled', (int) $cancelled['id']]);

$malmo = dbg_insert($store, 'Malmö Kund', [
    'customer_name' => 'Sten Håkansson',
    'address' => 'Huvudsväg 23, 184 90 Ljusterö',
    'notes' => '',
    'assignees' => ['Roberto'],
    'company' => 'malmo',
    'job_type' => 'batteri',
    'starts_at' => $mon . ' 09:00:00',
    'ends_at' => $mon . ' 11:00:00',
]);

$pnr = dbg_insert($store, 'Pnr Kund', [
    'customer_name' => 'Pnr Kund',
    'address' => '19900101-1234 Huvudsväg 23, 184 90 Ljusterö',
    'email' => 'pnr-secret@example.test',
    'phone' => '0701234567',
    'notes' => 'hemlig anteckning 199001011234',
    'assignees' => ['Roberto'],
    'company' => 'jarfalla',
    'job_type' => 'batteri',
    'starts_at' => $mon . ' 09:00:00',
    'ends_at' => $mon . ' 11:00:00',
]);

$ghost = dbg_insert($store, 'Spöke', [
    'customer_name' => 'Spöke',
    'address' => 'Storgatan 4, 177 40 Järfälla',
    'notes' => '',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'batteri',
    'starts_at' => $mon . ' 09:00:00',
    'ends_at' => $mon . ' 11:00:00',
]);
dbg_sql($store, 'UPDATE bookings SET assignee = ?, assignees = ? WHERE id = ?', ['ghost-id', '["ghost-id"]', (int) $ghost['id']]);

$elinBatteri = dbg_insert($store, 'Elin Batteri', [
    'customer_name' => 'Elin Batteri',
    'address' => 'Parkvägen 2, 177 40 Järfälla',
    'notes' => '',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'batteri',
    'starts_at' => $mon . ' 09:00:00',
    'ends_at' => $mon . ' 11:00:00',
]);

$psEmpty = dbg_insert($store, 'Ps Tom', [
    'customer_name' => 'Ps Tom',
    'address' => 'Parkvägen 8, 177 40 Järfälla',
    'notes' => '',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => $tue . ' 07:00:00',
    'ends_at' => $tue . ' 08:00:00',
]);

$psNotes = dbg_insert($store, 'Ps Anteckning', [
    'customer_name' => 'Ps Anteckning',
    'address' => 'Parkvägen 9, 177 40 Järfälla',
    'notes' => 'Ps 10',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => $tue . ' 07:00:00',
    'ends_at' => $tue . ' 08:00:00',
]);

$psLater = dbg_insert($store, 'Ps Sen', [
    'customer_name' => 'Ps Sen',
    'address' => 'Parkvägen 10, 177 40 Järfälla',
    'notes' => 'Ps 10',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => $tue . ' 13:00:00',
    'ends_at' => $tue . ' 14:00:00',
]);

$fullTitle = 'Batteri, Ljusterö huvuds väg 23 Ljusterö, Sten Håkansson';
$events19 = [
    [
        'who' => 'Roberto',
        'title' => $fullTitle,
        'starts_at' => $mon . ' 09:00:00',
        'ends_at' => $mon . ' 11:00:00',
        'all_day' => false,
    ],
    [
        'who' => 'Roberto',
        'title' => 'Batteri, Ljusterö',
        'starts_at' => $mon . ' 09:00:00',
        'ends_at' => $mon . ' 11:00:00',
        'all_day' => false,
    ],
    [
        'who' => 'Elin',
        'title' => 'Batteri',
        'starts_at' => $mon . ' 09:00:00',
        'ends_at' => $mon . ' 11:00:00',
        'all_day' => false,
    ],
];
$events20 = [
    [
        'who' => 'Elin',
        'title' => 'Ps 10',
        'starts_at' => $tue . ' 07:00:00',
        'ends_at' => $tue . ' 08:00:00',
        'all_day' => false,
    ],
];

$before = (int) $store->pdo()->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
$day19 = jeab_calendar_match_debug($store, $mon, 'Roberto', $events19);
$day20 = jeab_calendar_match_debug($store, $tue, 'Elin', $events20);
$elin19 = jeab_calendar_match_debug($store, $mon, 'Elin', $events19);
$after = (int) $store->pdo()->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
dbg_ok($before === $after && $before === 11, 'läsningen lämnar bokningstabellen orörd');

dbg_ok(($day19['read_only'] ?? false) === true && ($day19['ok'] ?? false) === true, 'svaret är en läsning');
dbg_ok(($day19['tz'] ?? '') === 'Europe/Stockholm', 'klockan jämförs i Europe/Stockholm');
dbg_ok(($day19['company'] ?? '') === 'jarfalla', 'Järfälla är valt kontor');
dbg_ok(($day19['graph_error'] ?? 'x') === '', 'inmatade händelser anropar inte Graph');
dbg_ok(count($day19['events'] ?? []) === 2, 'who=Roberto släpper Elins händelse samma dag');

$full = dbg_event($day19, 'Roberto', $fullTitle);
$shortEvent = dbg_event($day19, 'Roberto', 'Batteri, Ljusterö');
dbg_ok(is_array($full) && is_array($shortEvent), 'båda Roberto-ämnena finns med');

$stenRow = dbg_candidate($full, (int) $sten['id']);
dbg_ok(is_array($stenRow) && ($stenRow['match'] ?? false) === true, 'hela ämnet med gata och Sten Håkansson träffar den bokade raden');
dbg_ok(($stenRow['text'] ?? '') === 'strong', 'träffen är strong');
dbg_ok(($stenRow['company_raw'] ?? '') === 'Järfälla' && ($stenRow['company'] ?? '') === 'jarfalla', 'Järfälla i lagret är samma kontor som jarfalla');
dbg_ok(!in_array('office', $stenRow['why'] ?? ['office'], true), 'kontorsnyckeln avvisar inte Järfälla');
dbg_ok((int) ($full['matched_id'] ?? 0) === (int) $sten['id'], 'klicket skulle öppna Stens rad, där Roberto är tilldelad');

$shortRow = dbg_candidate($shortEvent, (int) $short['id']);
dbg_ok(is_array($shortRow) && ($shortRow['match'] ?? true) === false, 'kort ämne Batteri, Ljusterö träffar inte en gata utan ort och utan jobb');
dbg_ok(in_array('text', $shortRow['why'] ?? [], true) && ($shortRow['text'] ?? '') === 'none', 'avvisningen är text');
dbg_ok(($shortRow['who_on_booking'] ?? true) === false, 'Elin på raden är inte Robertos kalender');

$kimRow = dbg_candidate($shortEvent, (int) $kim['id']);
dbg_ok(is_array($kimRow) && ($kimRow['match'] ?? true) === false && in_array('text', $kimRow['why'] ?? [], true), 'ort utan gatunummer öppnar inte');
dbg_ok(($kimRow['street'] ?? true) === false && ($kimRow['name'] ?? true) === false, 'Ljusterö ensam är varken gata eller kundnamn');

$cancelRow = dbg_candidate($full, (int) $cancelled['id']);
dbg_ok(is_array($cancelRow) && ($cancelRow['match'] ?? true) === false && in_array('status', $cancelRow['why'] ?? [], true), 'avbokad rad syns och öppnas inte');
dbg_ok(($cancelRow['in_wave_pool'] ?? true) === false, 'avbokad rad ingår inte i veckovågen');

$malmoRow = dbg_candidate($full, (int) $malmo['id']);
dbg_ok(is_array($malmoRow) && in_array('office', $malmoRow['why'] ?? [], true) && ($malmoRow['match'] ?? true) === false, 'Malmö-raden avvisas när Järfälla är valt');

$encoded = json_encode($day19, JSON_UNESCAPED_UNICODE);
dbg_ok(is_string($encoded) && !str_contains($encoded, '19900101') && !str_contains($encoded, '199001011234'), 'personnummer lämnar inte svaret');
dbg_ok(is_string($encoded) && str_contains($encoded, '[pnr]'), 'personnummer ersätts med [pnr]');
dbg_ok(is_string($encoded) && !str_contains($encoded, 'pnr-secret@example.test') && !str_contains($encoded, '0701234567'), 'e-post och telefon lämnar inte svaret');
dbg_ok(is_string($encoded) && !str_contains($encoded, 'hemlig anteckning') && !str_contains($encoded, '"notes"') && !str_contains($encoded, '"email"') && !str_contains($encoded, '"phone"') && !str_contains($encoded, 'personnummer'), 'anteckningstext och kontaktsfält saknas');
$pnrRow = dbg_candidate($full, (int) $pnr['id']);
dbg_ok(is_array($pnrRow) && str_contains((string) ($pnrRow['address'] ?? ''), '[pnr]'), 'adressen med personnummer är maskerad');

$elinEvent = dbg_event($elin19, 'Elin', 'Batteri');
dbg_ok(is_array($elinEvent), 'Elins Batteri-händelse följer who=Elin');
$weak = dbg_candidate($elinEvent, (int) $elinBatteri['id']);
dbg_ok(is_array($weak) && ($weak['match'] ?? true) === false && in_array('text', $weak['why'] ?? [], true), 'bara jobbnamnet öppnas inte');
$ghostRow = dbg_candidate($elinEvent, (int) $ghost['id']);
dbg_ok(is_array($ghostRow) && ($ghostRow['match'] ?? true) === false && in_array('text', $ghostRow['why'] ?? [], true), 'ett id som inte blir ett namn öppnar inte ämnet Batteri');
dbg_ok(($ghostRow['assignee_raw'] ?? '') === 'ghost-id' && ($ghostRow['assignees'] ?? ['x']) === [], 'rått id syns och den upplösta listan är tom');

$ps = dbg_event($day20, 'Elin', 'Ps 10');
dbg_ok(is_array($ps), 'Ps 10 20 oktober finns med');
$emptyRow = dbg_candidate($ps, (int) $psEmpty['id']);
dbg_ok(is_array($emptyRow) && ($emptyRow['match'] ?? true) === false && in_array('text', $emptyRow['why'] ?? [], true), 'Ps 10 öppnas inte bara för att Elin äger kalendern');
dbg_ok(($emptyRow['who_on_booking'] ?? false) === true && ($emptyRow['text'] ?? '') === 'none', 'personen stämmer men ämnet saknar kund och gata');
$notesRow = dbg_candidate($ps, (int) $psNotes['id']);
dbg_ok(is_array($notesRow) && ($notesRow['match'] ?? true) === false && ($notesRow['notes_has_title'] ?? false) === true, 'Ps 10 i anteckningen öppnar inte');
dbg_ok(($ps['matched_id'] ?? null) === null, 'Ps 10 har ingen bokning att öppna');
$laterRow = dbg_candidate($ps, (int) $psLater['id']);
dbg_ok(is_array($laterRow) && ($laterRow['match'] ?? true) === false && in_array('text', $laterRow['why'] ?? [], true), 'Ps 10 en annan tid saknar också namn och gata');

$threw = false;
try {
    jeab_calendar_match_debug($store, '19/10', 'Roberto', []);
} catch (InvalidArgumentException) {
    $threw = true;
}
dbg_ok($threw, 'datum måste vara ÅÅÅÅ-MM-DD');

$root = dirname(__DIR__);
$index = (string) file_get_contents($root . '/index.php');
$boot = (string) file_get_contents($root . '/src/bootstrap.php');
$hook = (string) file_get_contents($root . '/hook.php');
dbg_ok(str_contains($index, "intent === 'calendar-match-debug'"), 'GET-intenten sitter på kalendersidan');
dbg_ok(str_contains($index, "case 'calendar.match_debug':"), 'POST-åtgärden heter calendar.match_debug');
dbg_ok(substr_count($boot, "'calendar-match-debug'") === 3, 'intenten hoppar över sidjobb, utskick och HTML-vyn');
dbg_ok(!str_contains($hook, 'calendar.match_debug') && !str_contains($hook, 'calendar-match-debug'), 'hook.php är orörd');

$GLOBALS['_JEAB_DEFER_TEST'] = true;
$_GET['intent'] = 'calendar-match-debug';
$_SERVER['REQUEST_METHOD'] = 'GET';
dbg_ok(jeab_request_skips_deferred_jobs(), 'debug-anropet startar inte uppskjutna jobb');
dbg_ok(jeab_request_skips_send_guard(), 'debug-anropet öppnar inte utskickskön');
dbg_ok(!jeab_page_is_html_view(), 'debug-anropet är inte en HTML-sida');

echo "calendar match debug ok\n";
