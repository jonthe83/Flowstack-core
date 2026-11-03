<?php

declare(strict_types=1);

/**
 * Batteri bokas 07:00–16:00. Laddbox stannar på halvdag. Sol stannar på fem vardagar.
 * En redan sparad 07–11-bokning skrivs inte om.
 */

$tmp = sys_get_temp_dir() . '/jeab-batteri-duration-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => [
        'driver' => 'json',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

function dur_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function dur_hours(string $start, string $end): float
{
    $a = new DateTimeImmutable($start);
    $b = new DateTimeImmutable($end);
    return ($b->getTimestamp() - $a->getTimestamp()) / 3600;
}

function dur_store(array $busy): JeabStore
{
    return new class($busy) implements JeabStore {
        public function __construct(private array $busy)
        {
        }
        public function listReminders(bool $includeCompleted = true): array { return []; }
        public function getReminder(int $id): ?array { return null; }
        public function insertReminder(array $data): array { return []; }
        public function saveReminder(int $id, array $fields): array { return []; }
        public function incrementReminderTap(int $id, string $field): array { return []; }
        public function deleteReminders(array $ids): int { return 0; }
        public function findOpenReminderByBooking(int $bookingId): ?array { return null; }
        public function findCustomerByName(string $name): ?array { return null; }
        public function insertCustomer(string $name, string $createdAt): array { return ['id' => 1, 'name' => $name]; }
        public function listCustomers(): array { return []; }
        public function recentAssignees(int $limit): array { return []; }
        public function getBooking(int $id): ?array { return null; }
        public function bookingsBetween(string $from, string $to): array { return $this->busy; }
        public function bookingsCreatedBetween(string $from, string $to): array { return []; }
        public function bookingsUpdatedBetween(string $from, string $to): array { return []; }
        public function listAllBookings(): array { return $this->busy; }
        public function insertBooking(array $data): array { return $data; }
        public function saveBooking(int $id, array $fields): array { return $fields; }
        public function setting(string $key, ?string $default = null): ?string { return $default; }
        public function setSetting(string $key, string $value): void {}
    };
}

function dur_busy(string $who, string $start, string $end): array
{
    return [[
        'company' => 'jarfalla',
        'assignees' => [$who],
        'assignee' => $who,
        'starts_at' => $start,
        'ends_at' => $end,
        'status' => 'booked',
    ]];
}

jeab_ordrar_external_live(false);

$click = jeab_booking_product_click_span('batteri', '2026-10-12T10:30');
dur_assert($click === ['2026-10-12T07:00', '2026-10-12T16:00'], 'kalenderklick batteri är 07:00–16:00, fick ' . json_encode($click));
dur_assert(dur_hours($click[0], $click[1]) === 9.0, 'kalenderklick batteri är 9 timmar');

$morning = jeab_booking_product_click_span('laddbox', '2026-10-12T09:00');
dur_assert($morning === ['2026-10-12T07:00', '2026-10-12T12:00'], 'kalenderklick laddbox förmiddag är 07:00–12:00, fick ' . json_encode($morning));
$afternoon = jeab_booking_product_click_span('laddbox', '2026-10-12T13:00');
dur_assert($afternoon === ['2026-10-12T12:00', '2026-10-12T16:00'], 'kalenderklick laddbox eftermiddag är 12:00–16:00, fick ' . json_encode($afternoon));

$solClick = jeab_booking_product_click_span('sol', '2026-10-14T08:00');
dur_assert($solClick === ['2026-10-12T00:00', '2026-10-17T00:00'], 'kalenderklick sol är måndag–lördag, fick ' . json_encode($solClick));
dur_assert(jeab_booking_weekday_count($solClick[0], $solClick[1]) === 5, 'kalenderklick sol är fem vardagar');

dur_assert(jeab_ordrar_day_windows('batteri') === [['07:00:00', '16:00:00']], 'ordrar-fönster för batteri är heldag');
dur_assert(jeab_ordrar_day_windows('laddbox') === [['07:00:00', '11:00:00'], ['12:00:00', '16:00:00']], 'ordrar-fönster för laddbox är halvdag');

$freeBat = jeab_ordrar_first_free_halfday_on_or_after(dur_store([]), 'Roberto', '2026-10-12', 'jarfalla', 'batteri');
dur_assert($freeBat['starts_at'] === '2026-10-12 07:00:00' && $freeBat['ends_at'] === '2026-10-12 16:00:00', 'ledig batteridag är 07:00–16:00, fick ' . json_encode($freeBat));
dur_assert(dur_hours($freeBat['starts_at'], $freeBat['ends_at']) === 9.0, 'ordrar batteri är 9 timmar');

$freeBox = jeab_ordrar_first_free_halfday_on_or_after(dur_store([]), 'Elin', '2026-10-12', 'jarfalla', 'laddbox');
dur_assert($freeBox['starts_at'] === '2026-10-12 07:00:00' && $freeBox['ends_at'] === '2026-10-12 11:00:00', 'ledig laddbox är förmiddag 07:00–11:00, fick ' . json_encode($freeBox));
dur_assert(!jeab_booking_is_all_day($freeBox['starts_at'], $freeBox['ends_at']), 'laddbox-halvdag är inte heldag');

$pmBusy = dur_busy('Roberto', '2026-10-12 12:00:00', '2026-10-12 16:00:00');
$batNext = jeab_ordrar_first_free_halfday_on_or_after(dur_store($pmBusy), 'Roberto', '2026-10-12', 'jarfalla', 'batteri');
dur_assert($batNext['starts_at'] === '2026-10-13 07:00:00' && $batNext['ends_at'] === '2026-10-13 16:00:00', 'upptagen eftermiddag flyttar batteri till nästa heldag, fick ' . json_encode($batNext));

$boxSame = jeab_ordrar_first_free_halfday_on_or_after(dur_store($pmBusy), 'Elin', '2026-10-12', 'jarfalla', 'laddbox');
dur_assert($boxSame['starts_at'] === '2026-10-12 07:00:00' && $boxSame['ends_at'] === '2026-10-12 11:00:00', 'laddbox tar förmiddagen när bara eftermiddagen är upptagen för någon annan');

$amBusy = dur_busy('Elin', '2026-10-13 07:00:00', '2026-10-13 11:00:00');
$boxPm = jeab_ordrar_first_free_halfday_on_or_after(dur_store($amBusy), 'Elin', '2026-10-13', 'jarfalla', 'laddbox');
dur_assert($boxPm['starts_at'] === '2026-10-13 12:00:00' && $boxPm['ends_at'] === '2026-10-13 16:00:00', 'upptagen förmiddag ger laddbox 12:00–16:00, fick ' . json_encode($boxPm));

$kept = dur_busy('Roberto', '2026-10-12 07:00:00', '2026-10-12 11:00:00');
$before = $kept[0]['ends_at'];
jeab_ordrar_first_free_halfday_on_or_after(dur_store($kept), 'Roberto', '2026-10-12', 'jarfalla', 'batteri');
dur_assert($kept[0]['ends_at'] === $before && $before === '2026-10-12 11:00:00', 'befintlig 07–11-bokning skrivs inte om');

$sol = jeab_ordrar_sol_span_from_day(new DateTimeImmutable('2026-10-12 00:00:00', new DateTimeZone('Europe/Stockholm')));
dur_assert($sol['starts_at'] === '2026-10-12 00:00:00' && $sol['ends_at'] === '2026-10-17 00:00:00', 'sol är fortfarande fem vardagar');
dur_assert(jeab_booking_weekday_count($sol['starts_at'], $sol['ends_at']) === 5, 'sol-spannet räknas som fem vardagar');

$ordrar = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$bookStart = strpos($ordrar, 'function jeab_ordrar_create_booking');
$bookEnd = strpos($ordrar, 'function jeab_ordrar_paminelse_mmdd');
dur_assert($bookStart !== false && $bookEnd !== false && $bookEnd > $bookStart, 'hittar create_booking');
$bookBody = substr($ordrar, $bookStart, $bookEnd - $bookStart);
dur_assert(str_contains($bookBody, 'jeab_ordrar_first_free_halfday_on_or_after($store, $who, $prefer, $company, $kind)'), 'Boka skickar produkt till heldags-/halvdagssök');
dur_assert(str_contains($bookBody, 'jeab_ordrar_first_free_slot($store, $kind, $who, $company,'), 'Boka utan datum skickar produkt');
dur_assert(!str_contains($bookBody, '11:00:00'), 'Boka hårdkodar inte 07–11');

$js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
dur_assert(preg_match("/if \\(job === 'batteri'\\) \\{[\\s\\S]{0,240}T07:00[\\s\\S]{0,80}T16:00/", $js) === 1, 'kalenderns klick sätter batteri till 07:00–16:00');
dur_assert(preg_match("/if \\(job === 'laddbox'\\) \\{[\\s\\S]{0,280}T07:00[\\s\\S]{0,80}T12:00/", $js) === 1, 'kalenderns klick sätter laddbox-förmiddag till 07:00–12:00');

$store = new JeabJsonStore($tmp . '/store.json');
$old = jeab_create_booking(
    $store,
    'Krokusvägen 10',
    'Roberto',
    '2026-10-12 07:00:00',
    '2026-10-12 11:00:00',
    'batteri',
    ['company' => 'jarfalla', 'address' => 'Krokusvägen 10', 'assignees' => ['Roberto']]
);
dur_assert(($old['ends_at'] ?? '') === '2026-10-12 11:00:00', 'sparad Krokus-tid ligger kvar på 11:00 innan ny bokning');

$reminder = jeab_create_reminder($store, 'Batteri ny', null, null, null, [
    'customer' => 'Batteri ny',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
    'address' => 'Testgatan 2',
]);
$row = jeab_purchase_start($store, (int) $reminder['id'], 'batteri');
$row['huvudsokande'] = 'Batteri ny';
$row['huvudsokande_address'] = 'Testgatan 2';
$row['company'] = 'jarfalla';
jeab_purchase_put($row);
$booked = jeab_ordrar_create_booking($store, [
    'id' => (int) $reminder['id'],
    'install_date' => '2026-10-12',
]);
$created = $store->getBooking((int) ($booked['booking_id'] ?? 0));
dur_assert(is_array($created), 'Ordrar Boka skapar en batteribokning');
dur_assert(($created['starts_at'] ?? '') === '2026-10-13 07:00:00', 'ny batteribokning börjar 07:00 dagen efter upptagen förmiddag, fick ' . ($created['starts_at'] ?? ''));
dur_assert(($created['ends_at'] ?? '') === '2026-10-13 16:00:00', 'ny batteribokning slutar 16:00, fick ' . ($created['ends_at'] ?? ''));
dur_assert(($created['job_type'] ?? '') === 'batteri', 'ny bokning är batteri');
$oldAgain = $store->getBooking((int) ($old['id'] ?? 0));
dur_assert(($oldAgain['starts_at'] ?? '') === '2026-10-12 07:00:00' && ($oldAgain['ends_at'] ?? '') === '2026-10-12 11:00:00', 'Krokus 07–11 flyttas inte när en ny batteridag bokas');

$boxReminder = jeab_create_reminder($store, 'Laddbox ny', null, null, null, [
    'customer' => 'Laddbox ny',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
]);
$boxRow = jeab_purchase_start($store, (int) $boxReminder['id'], 'laddbox');
$boxRow['huvudsokande'] = 'Laddbox ny';
$boxRow['company'] = 'jarfalla';
jeab_purchase_put($boxRow);
$boxBooked = jeab_ordrar_create_booking($store, [
    'id' => (int) $boxReminder['id'],
    'install_date' => '2026-10-14',
]);
$boxCreated = $store->getBooking((int) ($boxBooked['booking_id'] ?? 0));
dur_assert(($boxCreated['starts_at'] ?? '') === '2026-10-14 07:00:00' && ($boxCreated['ends_at'] ?? '') === '2026-10-14 11:00:00', 'Ordrar Boka laddbox är fortfarande 07:00–11:00, fick ' . json_encode($boxCreated));

$solReminder = jeab_create_reminder($store, 'Sol ny', null, null, null, [
    'customer' => 'Sol ny',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
]);
$solRow = jeab_purchase_start($store, (int) $solReminder['id'], 'sol');
$solRow['huvudsokande'] = 'Sol ny';
$solRow['company'] = 'jarfalla';
jeab_purchase_put($solRow);
$solBooked = jeab_ordrar_create_booking($store, [
    'id' => (int) $solReminder['id'],
    'install_date' => '2026-10-12',
]);
$solCreated = $store->getBooking((int) ($solBooked['booking_id'] ?? 0));
dur_assert(($solCreated['starts_at'] ?? '') === '2026-10-12 00:00:00' && ($solCreated['ends_at'] ?? '') === '2026-10-17 00:00:00', 'Ordrar Boka sol är fortfarande fem vardagar, fick ' . json_encode([$solCreated['starts_at'] ?? '', $solCreated['ends_at'] ?? '']));
dur_assert(jeab_booking_weekday_count((string) $solCreated['starts_at'], (string) $solCreated['ends_at']) === 5, 'skapad solbokning täcker fem vardagar');

echo "OK  batteri 07–16\n";
