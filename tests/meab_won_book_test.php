<?php

declare(strict_types=1);

/**
 * Malmö vunnen: laddbox-halvdag på Kim, batteri-heldag på Kim,
 * solceller måndag–tisdag eller torsdag–fredag på Isaq och Abol.
 * Onsdag är reserv. Järfälla/Elin bokas som tidigare.
 * Bekräftelse: contact@malmo.example.test, SMS +46700000003, inget SMS för sol.
 */

$tmp = sys_get_temp_dir() . '/jeab-meab-won-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'json',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

function mb_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function mb_day(string $ymd): DateTimeImmutable
{
    return new DateTimeImmutable($ymd . ' 00:00:00', new DateTimeZone('Europe/Stockholm'));
}

function mb_busy(string $who, string $start, string $end): array
{
    return [[
        'company' => 'malmo',
        'assignees' => [$who],
        'assignee' => $who,
        'starts_at' => $start,
        'ends_at' => $end,
        'status' => 'booked',
    ]];
}

function mb_names(array $booking): array
{
    $names = jeab_assignees_from_row($booking);
    sort($names);
    return $names;
}

function mb_hours(string $start, string $end): float
{
    $a = new DateTimeImmutable($start);
    $b = new DateTimeImmutable($end);
    return ($b->getTimestamp() - $a->getTimestamp()) / 3600;
}

date_default_timezone_set('Europe/Stockholm');
jeab_ordrar_external_live(false);

$tz = new DateTimeZone('Europe/Stockholm');
$monday = mb_day('2026-10-05');
$crew = jeab_ordrar_meab_sol_crew();
mb_assert($crew === ['Isaq', 'Abol'], 'sol-gänget är Isaq och Abol');

$mon = jeab_ordrar_meab_sol_pair_from_day($monday);
mb_assert(
    $mon === ['starts_at' => '2026-10-05 00:00:00', 'ends_at' => '2026-10-07 00:00:00'],
    'måndag ger måndag–tisdag, fick ' . json_encode($mon)
);
mb_assert(jeab_ordrar_meab_sol_pair_has_wednesday($mon) === false, 'måndag–tisdag innehåller inte onsdag');

$thu = jeab_ordrar_meab_sol_pair_from_day(mb_day('2026-10-08'));
mb_assert(
    $thu === ['starts_at' => '2026-10-08 00:00:00', 'ends_at' => '2026-10-10 00:00:00'],
    'torsdag ger torsdag–fredag, fick ' . json_encode($thu)
);
mb_assert(jeab_ordrar_meab_sol_pair_has_wednesday($thu) === false, 'torsdag–fredag innehåller inte onsdag');

foreach (['2026-10-06', '2026-10-07', '2026-10-09', '2026-10-10', '2026-10-11'] as $ymd) {
    $n = (int) mb_day($ymd)->format('N');
    mb_assert(jeab_ordrar_meab_sol_pair_from_day(mb_day($ymd)) === null, $ymd . ' (dag ' . $n . ') startar inget solpar');
}

$open = jeab_ordrar_pick_meab_sol_pair([], [], $crew, $monday);
mb_assert($open === $mon, 'ledig vecka tar måndag–tisdag först, fick ' . json_encode($open));

$isaqMonday = mb_busy('Isaq', '2026-10-05 07:00:00', '2026-10-05 11:00:00');
$afterIsaq = jeab_ordrar_pick_meab_sol_pair($isaqMonday, [], $crew, $monday);
mb_assert($afterIsaq === $thu, 'upptagen måndag hos Isaq ger torsdag–fredag, fick ' . json_encode($afterIsaq));

$abolMonday = mb_busy('Abol', '2026-10-05 00:00:00', '2026-10-07 00:00:00');
$afterAbol = jeab_ordrar_pick_meab_sol_pair($abolMonday, [], $crew, $monday);
mb_assert($afterAbol === $thu, 'upptagen måndag–tisdag hos Abol ger torsdag–fredag');

$wedOnly = mb_busy('Isaq', '2026-10-07 00:00:00', '2026-10-08 00:00:00');
$wedFree = jeab_ordrar_pick_meab_sol_pair($wedOnly, [], $crew, $monday);
mb_assert($wedFree === $mon, 'onsdag som reserv blockerar inte måndag–tisdag');

$fromWed = jeab_ordrar_pick_meab_sol_pair([], [], $crew, mb_day('2026-10-07'));
mb_assert($fromWed === $thu, 'sökning från onsdag hoppar till torsdag–fredag, fick ' . json_encode($fromWed));

$fromTue = jeab_ordrar_pick_meab_sol_pair([], [], $crew, mb_day('2026-10-06'));
mb_assert($fromTue === $thu, 'sökning från tisdag tar inte onsdag, fick ' . json_encode($fromTue));

$both = array_merge(
    mb_busy('Isaq', '2026-10-05 00:00:00', '2026-10-07 00:00:00'),
    mb_busy('Abol', '2026-10-08 00:00:00', '2026-10-10 00:00:00')
);
$nextWeek = jeab_ordrar_pick_meab_sol_pair($both, [], $crew, $monday);
mb_assert(
    $nextWeek === ['starts_at' => '2026-10-12 00:00:00', 'ends_at' => '2026-10-14 00:00:00'],
    'båda paren upptagna ger nästa måndag–tisdag, fick ' . json_encode($nextWeek)
);
mb_assert(jeab_ordrar_meab_sol_pair_has_wednesday($nextWeek) === false, 'nästa par är inte onsdag');

$jeabSol = jeab_ordrar_sol_span_from_day($monday);
mb_assert(
    $jeabSol['starts_at'] === '2026-10-05 00:00:00' && $jeabSol['ends_at'] === '2026-10-10 00:00:00',
    'JEAB-sol är fortfarande fem vardagar'
);
mb_assert(jeab_ordrar_book_who('laddbox') === 'Elin', 'JEAB-laddbox bokas på Elin');
mb_assert(jeab_ordrar_book_who('batteri') === 'Roberto', 'JEAB-batteri bokas på Roberto');
mb_assert(jeab_ordrar_book_who('sol') === 'Niman', 'JEAB-sol bokas på installatören Niman');
mb_assert(jeab_ordrar_book_who('laddbox', 'malmo') === 'Kim', 'MEAB-laddbox pekar på Kim');
mb_assert(jeab_ordrar_book_who('batteri', 'meab') === 'Kim', 'MEAB-batteri pekar på Kim');
mb_assert(jeab_ordrar_book_who('sol', 'malmo') === 'Isaq', 'MEAB-sol pekar på Isaq');

$store = jeab_store();

function mb_won(JeabStore $store, string $customer, string $company, string $who, string $note, string $email = '', string $phone = ''): array
{
    $slug = preg_replace('/[^a-z0-9]+/i', '', $customer) ?: 'kund';
    $reminder = jeab_create_reminder($store, $customer, null, $note, null, [
        'customer' => $customer,
        'company' => $company,
        'assignee' => $who,
        'tags' => ['vunnen'],
        'email' => $email !== '' ? $email : strtolower($slug) . '@example.com',
        'phone' => $phone !== '' ? $phone : '070' . str_pad((string) (abs(crc32($slug)) % 10000000), 7, '0', STR_PAD_LEFT),
        'address' => 'Testgatan 1',
        'notes' => $note,
    ]);
    return jeab_ordrar_vunnen_purchase($store, (int) $reminder['id'], $reminder);
}

function mb_booking(JeabStore $store, array $purchase): ?array
{
    $id = (int) ($purchase['booking_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $row = $store->getBooking($id);
    return is_array($row) ? $row : null;
}

function mb_bekrafta_step(array $purchase): array
{
    foreach (jeab_ordrar_v3_steps($purchase) as $step) {
        if (($step['id'] ?? '') === 'bekrafta') {
            return $step;
        }
    }
    return ['id' => 'bekrafta', 'state' => 'todo', 'n' => 1];
}

function mb_open_shows_slot(JeabStore $store, array $purchase, string $who): array
{
    $before = count($store->listAllBookings());
    $rid = (int) ($purchase['reminder_id'] ?? 0);
    mb_assert((int) ($purchase['booking_id'] ?? 0) === 0, $who . ' vunnen skapar ingen bokning');
    mb_assert(count($store->listAllBookings()) === $before, $who . ' öppning skriver ingen bokning');
    $slot = jeab_ordrar_proposed_slot($store, $purchase);
    mb_assert(is_array($slot), $who . ' har en föreslagen slot');
    $reminder = jeab_get_reminder($store, $rid);
    $html = jeab_ordrar_v3_bekrafta_html($purchase, 'malmo', $rid, mb_bekrafta_step($purchase), $store, is_array($reminder) ? $reminder : null);
    $stamp = (string) $slot['starts_at'] . '|' . (string) $slot['ends_at'];
    if (jeab_ordrar_books_at_bestall($purchase)) {
        mb_assert(!str_contains($html, 'Föreslagen tid') && !str_contains($html, 'name="install_date"'), $who . ' visar ingen installationslucka i Bekräfta');
        mb_assert(str_contains($html, '>Skapa order<') && !str_contains($html, '>Skapa order och boka<'), $who . ' skapar ordern i Bekräfta och bokar i Beställ');
        $proposal = jeab_ordrar_install_proposal($store, $purchase);
        $block = jeab_ordrar_proposal_block_html($proposal);
        mb_assert(is_array($proposal) && (string) ($proposal['starts_at'] ?? '') === (string) $slot['starts_at'], $who . ' förslaget är samma lucka');
        mb_assert(str_contains($block, 'data-proposed-slot="' . $stamp . '"') && str_contains($block, 'name="proposal_starts"'), $who . ' Beställ visar luckan');
        mb_assert(!str_contains($block, 'type="date" name="onskad_leverans"'), $who . ' leveransdatumet går inte att ändra');
    } else {
        mb_assert(str_contains($html, 'Föreslagen tid') && str_contains($html, 'data-proposed-slot="' . $stamp . '"'), $who . ' visar sloten i Bekräfta');
        mb_assert(str_contains($html, 'name="install_date"') && str_contains($html, 'form="v3-f-bekrafta"'), $who . ' kan byta lucka i Bekräfta');
        mb_assert(str_contains($html, '>Skapa order och boka<'), $who . ' bokar först vid Bekräfta');
    }
    mb_assert(count($store->listAllBookings()) === $before, $who . ' Bekräfta-sidan skapar ingen bokning');
    mb_assert(is_array($reminder) && (int) ($reminder['id'] ?? 0) === $rid, $who . ' påminnelsen finns kvar');
    return $slot;
}

$before = count($store->listAllBookings());
$isaq = mb_won($store, 'Isaq Ingen', 'malmo', 'Isaq', 'laddbox');
mb_assert((int) ($isaq['booking_id'] ?? 0) === 0, 'laddbox på Isaq bokar inte Kim');
mb_assert(count($store->listAllBookings()) === $before, 'fel ansvarig skapar ingen bokning');
$isaqHtml = jeab_ordrar_v3_bekrafta_html($isaq, 'malmo', (int) ($isaq['reminder_id'] ?? 0), mb_bekrafta_step($isaq), $store, null);
mb_assert(count($store->listAllBookings()) === $before, 'Isaqs Bekräfta-sida skapar ingen bokning');
mb_assert(str_contains($isaqHtml, 'Föreslagen tid') && str_contains($isaqHtml, 'Kim'), 'Isaqs laddbox visar Kims tid innan Bekräfta');

$jarfalla = mb_won($store, 'Elin Kund', 'jarfalla', 'Elin', 'laddbox');
mb_assert((int) ($jarfalla['booking_id'] ?? 0) === 0, 'Järfälla vunnen autobokar inte');
$jarfalla['kind'] = 'laddbox';
$jarfalla = jeab_purchase_put($jarfalla);
$elinBooked = jeab_ordrar_create_booking($store, ['id' => (int) $jarfalla['reminder_id']]);
$elin = mb_booking($store, $elinBooked);
mb_assert(is_array($elin), 'JEAB-laddbox kan fortfarande bokas');
mb_assert(mb_names($elin) === ['Elin'], 'JEAB-laddbox ligger på Elin, fick ' . json_encode(mb_names($elin)));
mb_assert(mb_hours((string) $elin['starts_at'], (string) $elin['ends_at']) === 4.0, 'JEAB-laddbox är fortfarande en halvdag');
mb_assert(
    str_contains((string) $elin['starts_at'], '07:00:00') || str_contains((string) $elin['starts_at'], '12:00:00'),
    'JEAB-halvdag börjar 07 eller 12'
);

$ambiguous = mb_won($store, 'Otydlig Kund', 'malmo', 'Kim', 'laddbox och batteri');
mb_assert((int) ($ambiguous['booking_id'] ?? 0) === 0, 'två produkter bokar inte');

$half = [];
foreach (['Kim', 'Abol', 'Sam W', 'SamW', 'sam w'] as $i => $who) {
    $purchase = mb_won($store, 'Laddbox ' . $i, 'malmo', $who, 'Elbilsladdare');
    $slot = mb_open_shows_slot($store, $purchase, $who);
    mb_assert($slot['who'] === ['Kim'], $who . ' föreslår Kim, fick ' . json_encode($slot['who']));
    mb_assert(mb_hours((string) $slot['starts_at'], (string) $slot['ends_at']) === 4.0, $who . ' föreslår en halvdag');
    $purchase = jeab_ordrar_create_booking($store, ['id' => (int) $purchase['reminder_id']]);
    $booking = mb_booking($store, $purchase);
    mb_assert(is_array($booking), $who . ' Bekräfta bokar');
    mb_assert((string) $booking['starts_at'] === (string) $slot['starts_at'] && (string) $booking['ends_at'] === (string) $slot['ends_at'], $who . ' bokar den visade sloten');
    mb_assert(mb_names($booking) === ['Kim'], $who . ' bokar Kim, fick ' . json_encode(mb_names($booking)));
    mb_assert(($booking['company'] ?? '') === 'malmo', $who . ' sparas som malmo');
    mb_assert(($booking['job_type'] ?? '') === 'laddbox', $who . ' är laddbox');
    mb_assert(mb_hours((string) $booking['starts_at'], (string) $booking['ends_at']) === 4.0, $who . ' är halvdag');
    $start = new DateTimeImmutable((string) $booking['starts_at'], $tz);
    $hour = (int) $start->format('G');
    mb_assert($hour === 7 || $hour === 12, $who . ' börjar 07 eller 12, fick ' . $hour);
    mb_assert((int) $start->format('N') <= 5, $who . ' ligger på en vardag');
    $linked = jeab_get_reminder($store, (int) $purchase['reminder_id']);
    mb_assert(is_array($linked) && (int) ($purchase['booking_id'] ?? 0) === (int) ($booking['id'] ?? 0), $who . ' ordern behåller bokningen');
    $afterHtml = jeab_ordrar_v3_bekrafta_html($purchase, 'malmo', (int) $purchase['reminder_id'], mb_bekrafta_step($purchase), $store, $linked);
    mb_assert(!str_contains($afterHtml, 'data-proposed-slot='), $who . ' redan bokad tid får ingen ny slot');
    mb_assert((int) (jeab_purchase_get((int) $purchase['reminder_id'])['booking_id'] ?? 0) === (int) $booking['id'], $who . ' öppning igen behåller bokningskopplingen');
    $half[] = $booking;
}
for ($i = 0; $i < count($half); $i++) {
    for ($j = $i + 1; $j < count($half); $j++) {
        $overlap = jeab_ordrar_times_overlap(
            (string) $half[$i]['starts_at'],
            (string) $half[$i]['ends_at'],
            (string) $half[$j]['starts_at'],
            (string) $half[$j]['ends_at']
        );
        mb_assert(!$overlap, 'Kim-halvdagar överlappar inte');
    }
}

$battery = mb_won($store, 'Batteri Kund', 'malmo', 'Isaq', 'batteri');
$batSlot = mb_open_shows_slot($store, $battery, 'batteri');
mb_assert($batSlot['who'] === ['Kim'], 'batteri föreslår Kim');
$battery = jeab_ordrar_create_booking($store, ['id' => (int) $battery['reminder_id']]);
$bat = mb_booking($store, $battery);
mb_assert(is_array($bat), 'Malmö-batteri bokas vid Bekräfta');
mb_assert((string) $bat['starts_at'] === (string) $batSlot['starts_at'], 'batteri bokar den visade sloten');
mb_assert(mb_names($bat) === ['Kim'], 'batteri bokas på Kim, fick ' . json_encode(mb_names($bat)));
mb_assert(($bat['job_type'] ?? '') === 'batteri', 'batteri-bokningen är batteri');
mb_assert(
    str_contains((string) $bat['starts_at'], '07:00:00') && str_contains((string) $bat['ends_at'], '16:00:00'),
    'batteri är 07:00–16:00, fick ' . ($bat['starts_at'] ?? '') . '–' . ($bat['ends_at'] ?? '')
);
mb_assert(mb_hours((string) $bat['starts_at'], (string) $bat['ends_at']) === 9.0, 'batteri är 9 timmar');

$solar = mb_won($store, 'Sol Kund', 'malmo', 'Kim', 'solceller');
$solSlot = mb_open_shows_slot($store, $solar, 'sol');
mb_assert($solSlot['who'] === ['Isaq', 'Abol'], 'sol föreslår Isaq och Abol, fick ' . json_encode($solSlot['who']));
$solar = jeab_ordrar_create_booking($store, ['id' => (int) $solar['reminder_id']]);
$sol = mb_booking($store, $solar);
mb_assert(is_array($sol), 'Malmö-sol bokas vid Bekräfta');
mb_assert((string) $sol['starts_at'] === (string) $solSlot['starts_at'] && (string) $sol['ends_at'] === (string) $solSlot['ends_at'], 'sol bokar den visade sloten');
mb_assert(mb_names($sol) === ['Abol', 'Isaq'], 'sol bokas på Isaq och Abol, fick ' . json_encode(mb_names($sol)));
mb_assert(($sol['company'] ?? '') === 'malmo', 'sol sparas som malmo');
mb_assert(($sol['job_type'] ?? '') === 'sol', 'sol-bokningen är sol');
$solStart = new DateTimeImmutable((string) $sol['starts_at'], $tz);
$solEnd = new DateTimeImmutable((string) $sol['ends_at'], $tz);
$solN = (int) $solStart->format('N');
mb_assert($solN === 1 || $solN === 4, 'sol startar måndag eller torsdag, fick dag ' . $solN);
mb_assert($solEnd->format('Y-m-d H:i:s') === $solStart->modify('+2 days')->format('Y-m-d H:i:s'), 'sol är två sammanhängande dagar');
mb_assert(jeab_ordrar_meab_sol_pair_has_wednesday([
    'starts_at' => (string) $sol['starts_at'],
    'ends_at' => (string) $sol['ends_at'],
]) === false, 'bokad sol innehåller inte onsdag');
mb_assert(jeab_booking_is_all_day((string) $sol['starts_at'], (string) $sol['ends_at']), 'solparen är heldagar');

$moved = jeab_ordrar_move_booking_for_delivery($solar, '2026-10-07', $store);
$movedBooking = mb_booking($store, $moved);
mb_assert(is_array($movedBooking), 'flyttad sol finns kvar');
mb_assert(
    ($movedBooking['starts_at'] ?? '') === '2026-10-15 00:00:00' && ($movedBooking['ends_at'] ?? '') === '2026-10-17 00:00:00',
    'onsdag som behov flyttas till torsdag–fredag, inte fem JEAB-dagar, fick ' . json_encode($movedBooking)
);

$jeabSolWon = mb_won($store, 'Sol JEAB', 'jarfalla', 'Niman', 'solceller');
mb_assert((int) ($jeabSolWon['booking_id'] ?? 0) === 0, 'Järfälla-sol autobokas inte som MEAB-par');

mb_assert(jeab_booking_confirm_mail_from(['company' => 'malmo', 'job_type' => 'laddbox']) === 'kontakt@malmo.example.test', 'laddbox-mejl är kontakt@');
mb_assert(jeab_booking_confirm_mail_from(['company' => 'malmo', 'job_type' => 'batteri']) === 'kontakt@malmo.example.test', 'batteri-mejl är kontakt@');
mb_assert(jeab_booking_confirm_mail_from(['company' => 'malmo', 'job_type' => 'sol']) === 'kontakt@malmo.example.test', 'sol-mejl är kontakt@');
mb_assert(jeab_booking_confirm_mail_from(['company' => 'jarfalla', 'job_type' => 'laddbox']) === null, 'JEAB behåller sin vanliga From');
mb_assert(jeab_system_mail_address('malmo') === 'kontakt@malmo.example.test', 'övrig MEAB-post är fortfarande kontakt@');
mb_assert(
    jeab_system_mail_from_identity(['company' => 'malmo', 'from_email' => 'contact@malmo.example.test', 'from_name' => 'Demo Energi Malmö'])['email'] === 'contact@malmo.example.test',
    'bekräftelsens From-identitet är contact@malmo.example.test'
);
mb_assert(
    jeab_system_mail_from_identity(['company' => 'jarfalla'])['email'] === 'kontakt@jarfalla.example.test',
    'JEAB From-identitet är oförändrad'
);

mb_assert(jeab_booking_confirm_sms_from(['company' => 'malmo', 'job_type' => 'laddbox']) === '+46700000003', 'laddbox-SMS är Malmö-numret');
mb_assert(jeab_booking_confirm_sms_from(['company' => 'malmo', 'job_type' => 'batteri']) === '+46700000003', 'batteri-SMS är Malmö-numret');
mb_assert(jeab_booking_confirm_sms_from(['company' => 'malmo', 'job_type' => 'sol']) === null, 'sol har ingen SMS-avsändare');
mb_assert(jeab_booking_confirm_skips_sms(['company' => 'malmo', 'job_type' => 'sol']), 'Malmö-sol hoppar över SMS');
mb_assert(!jeab_booking_confirm_skips_sms(['company' => 'jarfalla', 'job_type' => 'sol']), 'JEAB-sol hoppas inte över');
mb_assert(jeab_booking_confirm_sms_from(['company' => 'jarfalla', 'job_type' => 'laddbox']) === null, 'JEAB-SMS använder inte Malmö-numret');

$cfg = ['login' => 'APIKey', 'password' => 'x', 'from' => 'JEAB', 'from_malmo' => 'MEAB'];
$forced = jeab_sms_request_query('+46700000000', 'text', 'malmo', $cfg, '+46700000003');
mb_assert(($forced['from'] ?? '') === '+46700000003', 'Malmö-bekräftelse tvingar SMS-avsändaren');
$plain = jeab_sms_request_query('+46700000000', 'text', 'jarfalla', $cfg);
mb_assert(($plain['from'] ?? '') === 'JEAB', 'Järfälla använder fortfarande from');
$meabCfg = jeab_sms_request_query('+46700000000', 'text', 'malmo', $cfg);
mb_assert(($meabCfg['from'] ?? '') === 'MEAB', 'utan override används from_malmo');

$captured = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$captured): array {
    $captured[] = $opts;
    return ['ok' => true, 'path' => 'graph', 'error' => null, 'id' => 'stub'];
};
$aged = (new DateTimeImmutable('now', $tz))->modify('-3 hours')->format('Y-m-d H:i:s');
$future = (new DateTimeImmutable('now', $tz))->modify('+3 days')->setTime(9, 0)->format('Y-m-d H:i:s');
foreach ([
    ['company' => 'malmo', 'job_type' => 'laddbox', 'email' => 'malmo-ladd@example.com'],
    ['company' => 'malmo', 'job_type' => 'batteri', 'email' => 'malmo-bat@example.com'],
    ['company' => 'malmo', 'job_type' => 'sol', 'email' => 'malmo-sol@example.com'],
    ['company' => 'jarfalla', 'job_type' => 'laddbox', 'email' => 'jeab-ladd@example.com'],
] as $i => $sample) {
    jeab_booking_send_customer_mail([
        'id' => 9100 + $i,
        'status' => 'booked',
        'customer_name' => 'Kund',
        'email' => $sample['email'],
        'starts_at' => $future,
        'ends_at' => (new DateTimeImmutable($future, $tz))->modify('+4 hours')->format('Y-m-d H:i:s'),
        'created_at' => $aged,
        'updated_at' => $aged,
        'company' => $sample['company'],
        'job_type' => $sample['job_type'],
        'assignee' => $sample['company'] === 'malmo' ? 'Kim' : 'Elin',
    ]);
}
$byTo = [];
foreach ($captured as $mail) {
    $byTo[(string) ($mail['to'] ?? '')] = $mail;
}
foreach (['malmo-ladd@example.com', 'malmo-bat@example.com'] as $to) {
    mb_assert(($byTo[$to]['from_email'] ?? '') === 'kontakt@malmo.example.test', $to . ' får kontakt@');
    mb_assert(($byTo[$to]['company'] ?? '') === 'malmo', $to . ' är malmo');
}
mb_assert(!isset($byTo['malmo-sol@example.com']), 'Malmö-sol skickar ingen bekräftelse när kalendermejl är av');
mb_assert(!array_key_exists('from_email', $byTo['jeab-ladd@example.com']), 'JEAB-bekräftelse sätter inte Malmö-From');
jeab_auto_settings_save([
    'auto_trash_spam' => 1,
    'sol_calendar_mail_off' => 0,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_booking_send_customer_mail([
    'id' => 9199,
    'status' => 'booked',
    'customer_name' => 'Kund',
    'email' => 'malmo-sol-pa@example.com',
    'starts_at' => $future,
    'ends_at' => (new DateTimeImmutable($future, $tz))->modify('+4 hours')->format('Y-m-d H:i:s'),
    'created_at' => $aged,
    'updated_at' => $aged,
    'company' => 'malmo',
    'job_type' => 'sol',
    'assignee' => 'Sam W',
]);
$solOn = null;
foreach ($captured as $mail) {
    if (($mail['to'] ?? '') === 'malmo-sol-pa@example.com') {
        $solOn = $mail;
    }
}
mb_assert(is_array($solOn) && ($solOn['from_email'] ?? '') === 'kontakt@malmo.example.test', 'avbockad spärr släpper Malmö-sol från kontakt@');

mb_assert(jeab_malmo_job_crew('sol') === ['Isaq', 'Abol'], 'en lista: sol är Isaq och Abol');
mb_assert(jeab_malmo_job_crew('laddbox') === ['Kim'] && jeab_malmo_job_crew('batteri') === ['Kim'], 'en lista: laddbox och batteri är Kim');
mb_assert(jeab_ordrar_meab_sol_crew() === jeab_malmo_job_crew('sol'), 'sol-gänget läser samma lista');
mb_assert(jeab_ordrar_meab_book_crew('batteri', []) === jeab_malmo_job_crew('batteri'), 'batteri-gänget läser samma lista');
mb_assert(jeab_ordrar_book_who('sol', 'malmo') === 'Isaq', 'primär sol är fortfarande Isaq');

$everyone = ['Isaq', 'Abol', 'Sam W', 'Kim'];
$wedSol = jeab_create_booking($store, 'Onsdag Sol', $everyone, '2026-10-07 09:00:00', '2026-10-07 12:00:00', 'sol', [
    'company' => 'malmo',
    'assignees' => $everyone,
    'email' => 'onsdag-sol@example.com',
]);
mb_assert(mb_names($wedSol) === ['Abol', 'Isaq'], 'onsdagssol sparar bara Isaq och Abol, fick ' . json_encode(mb_names($wedSol)));
mb_assert(($wedSol['starts_at'] ?? '') === '2026-10-08 00:00:00' && ($wedSol['ends_at'] ?? '') === '2026-10-10 00:00:00', 'onsdag blir torsdag–fredag, fick ' . json_encode([$wedSol['starts_at'] ?? '', $wedSol['ends_at'] ?? '']));
mb_assert(jeab_malmo_span_hits_wednesday((string) $wedSol['starts_at'], (string) $wedSol['ends_at']) === false, 'sparad sol träffar inte onsdag');

$five = jeab_create_booking($store, 'Femdagar', $everyone, '2026-10-05 00:00:00', '2026-10-10 00:00:00', 'sol', [
    'company' => 'malmo',
    'assignees' => $everyone,
]);
mb_assert(($five['starts_at'] ?? '') === '2026-10-05 00:00:00' && ($five['ends_at'] ?? '') === '2026-10-07 00:00:00', 'fem vardagar blir måndag–tisdag, fick ' . json_encode([$five['starts_at'] ?? '', $five['ends_at'] ?? '']));
mb_assert(mb_names($five) === ['Abol', 'Isaq'], 'femdagar släpper inte in Sam W eller Kim');

$kimWed = jeab_create_booking($store, 'Kim Ons', $everyone, '2026-10-07 07:00:00', '2026-10-07 12:00:00', 'laddbox', [
    'company' => 'malmo',
    'assignees' => $everyone,
]);
mb_assert(mb_names($kimWed) === ['Kim'], 'laddbox är bara Kim, fick ' . json_encode(mb_names($kimWed)));
mb_assert((string) ($kimWed['starts_at'] ?? '') === '2026-10-07 07:00:00' && (string) ($kimWed['ends_at'] ?? '') === '2026-10-07 12:00:00', 'Kims halvdag får ligga på onsdag');

$bat = jeab_create_booking($store, 'Alla Batteri', $everyone, '2026-10-08 07:00:00', '2026-10-08 16:00:00', 'batteri', [
    'company' => 'malmo',
    'assignees' => $everyone,
]);
mb_assert(mb_names($bat) === ['Kim'], 'batteri är bara Kim, fick ' . json_encode(mb_names($bat)));
mb_assert((string) ($bat['starts_at'] ?? '') === '2026-10-08 07:00:00' && (string) ($bat['ends_at'] ?? '') === '2026-10-08 16:00:00', 'batteri-heldag rörs inte');

$jeabFive = jeab_create_booking($store, 'JEAB Sol', ['Sam L'], '2026-10-05 00:00:00', '2026-10-10 00:00:00', 'sol', [
    'company' => 'jarfalla',
    'assignees' => ['Sam L'],
]);
mb_assert(mb_names($jeabFive) === ['Sam L'], 'JEAB-sol byter inte personal');
mb_assert((string) ($jeabFive['starts_at'] ?? '') === '2026-10-05 00:00:00', 'JEAB-sol startar kvar på måndag');
mb_assert(str_starts_with((string) ($jeabFive['ends_at'] ?? ''), '2026-10-10'), 'JEAB-sol är kvar över fem vardagar, fick ' . (string) ($jeabFive['ends_at'] ?? ''));

$week43 = $store->insertBooking([
    'customer_id' => 1,
    'customer_name' => 'Vecka 43 lämnas',
    'email' => null,
    'phone' => null,
    'address' => 'Glimmingegatan 5',
    'notes' => null,
    'assignees' => $everyone,
    'company' => 'malmo',
    'job_type' => 'sol',
    'starts_at' => '2026-10-21 00:00:00',
    'ends_at' => '2026-10-22 00:00:00',
    'status' => 'booked',
    'created_at' => '2026-10-01 08:00:00',
    'updated_at' => '2026-10-01 08:00:00',
]);
$week43id = (int) $week43['id'];
$noted = jeab_update_booking($store, $week43id, ['notes' => 'anteckning utan personalbyte']);
mb_assert(mb_names($noted) === ['Abol', 'Isaq', 'Sam W', 'Kim'] || mb_names($noted) === ['Abol', 'Isaq', 'Kim', 'Sam W'], 'vecka 43 får ny anteckning utan att personalen skrivs om, fick ' . json_encode(mb_names($noted)));
mb_assert((string) ($noted['starts_at'] ?? '') === '2026-10-21 00:00:00' && (string) ($noted['ends_at'] ?? '') === '2026-10-22 00:00:00', 'vecka 43 flyttas inte vid anteckning');

$assigned = jeab_update_booking($store, (int) $kimWed['id'], [
    'assignees' => $everyone,
    'job_type' => 'laddbox',
    'starts_at' => (string) $kimWed['starts_at'],
    'ends_at' => (string) $kimWed['ends_at'],
    'company' => 'malmo',
]);
mb_assert(mb_names($assigned) === ['Kim'], 'ny personal-tilldelning på laddbox släpper inte in de andra');

echo "meab won book: ok\n";
