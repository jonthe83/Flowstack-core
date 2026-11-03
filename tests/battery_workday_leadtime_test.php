<?php

declare(strict_types=1);

/**
 * Batteri bokas tidigast fjärde arbetsdagen efter beställningsdagen (leverans 3 arbetsdagar + 1).
 * Helger och svenska röda dagar räknas inte. Laddbox 2 dagar, sol 14 dagar.
 * Samma regel gäller i Boka (create_booking) och i Boka+Beställ (suggest_install_ymd).
 */

$tmp = sys_get_temp_dir() . '/jeab-battery-workday-' . getmypid();
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

function lead_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function lead_day(string $ymd): DateTimeImmutable
{
    return new DateTimeImmutable($ymd . ' 09:30:00', new DateTimeZone('Europe/Stockholm'));
}

function lead_store(array $busy = []): JeabStore
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

jeab_ordrar_external_live(false);

// Röda dagar
$h26 = jeab_ordrar_se_holidays(2026);
lead_assert(isset($h26['2026-04-03'], $h26['2026-04-06']), 'långfredag och annandag påsk 2026');
lead_assert(isset($h26['2026-05-14']), 'Kristi himmelsfärd 2026 är 14/5');
lead_assert(isset($h26['2026-06-19'], $h26['2026-06-20']), 'midsommarafton 19/6 och midsommardagen 20/6 2026');
lead_assert(isset($h26['2026-10-31']), 'alla helgons dag 2026 är 31/10');
lead_assert(isset($h26['2026-12-24'], $h26['2026-12-25'], $h26['2026-12-26'], $h26['2026-12-31']), 'jul och nyårsafton 2026');
$h27 = jeab_ordrar_se_holidays(2027);
lead_assert(isset($h27['2027-03-26'], $h27['2027-03-29']), 'påsk 2027: långfredag 26/3, annandag 29/3');
lead_assert(isset($h27['2027-05-06']), 'Kristi himmelsfärd 2027 är 6/5');
lead_assert(isset($h27['2027-06-25']), 'midsommarafton 2027 är 25/6');
lead_assert(!jeab_ordrar_is_workday(lead_day('2026-12-24')) && jeab_ordrar_is_workday(lead_day('2026-12-23')), 'julafton är inte arbetsdag');

// Batteri: fjärde arbetsdagen efter beställningsdagen
$cases = [
    '2026-10-07' => '2026-10-13', // ons → tis (Sams exempel)
    '2026-10-09' => '2026-10-15', // fre → tors
    '2026-10-10' => '2026-10-15', // lör → tors
    '2026-12-22' => '2026-12-30', // tis före jul: jul, annandag och helg hoppas över
    '2027-05-04' => '2027-05-11', // tis före Kristi himmelsfärd 6/5
    '2027-05-05' => '2027-05-12', // ons före Kristi himmelsfärd
    '2026-12-29' => '2027-01-07', // nyårsafton, nyårsdagen och trettondedag jul hoppas över
];
foreach ($cases as $order => $want) {
    $got = jeab_ordrar_book_earliest_day('batteri', lead_day($order))->format('Y-m-d');
    lead_assert($got === $want, "batteri beställt {$order} ger tidigast {$want}, fick {$got}");
}

// Laddbox 2 dagar, sol 14 dagar
lead_assert(jeab_ordrar_book_earliest_day('laddbox', lead_day('2026-10-07'))->format('Y-m-d') === '2026-10-09', 'laddbox är fortfarande 2 dagar');
lead_assert(jeab_ordrar_book_earliest_day('sol', lead_day('2026-10-07'))->format('Y-m-d') === '2026-10-21', 'sol är fortfarande 14 dagar');

// Gemensam sökning
$bat = jeab_ordrar_first_free_slot(lead_store(), 'batteri', 'Roberto', 'jarfalla', null, lead_day('2026-10-07'));
lead_assert($bat === ['starts_at' => '2026-10-13 07:00:00', 'ends_at' => '2026-10-13 16:00:00'], 'batteri Järfälla beställt ons 7/10 föreslås tis 13/10 07–16, fick ' . json_encode($bat));
$batKim = jeab_ordrar_first_free_slot(lead_store(), 'batteri', 'Kim', 'malmo', null, lead_day('2026-10-07'));
lead_assert(($batKim['starts_at'] ?? '') === '2026-10-13 07:00:00', 'batteri Malmö (Kim) beställt ons 7/10 föreslås tis 13/10, fick ' . json_encode($batKim));
$busy13 = [[
    'company' => 'jarfalla', 'assignees' => ['Roberto'], 'assignee' => 'Roberto',
    'starts_at' => '2026-10-13 07:00:00', 'ends_at' => '2026-10-13 16:00:00', 'status' => 'booked',
]];
$batNext = jeab_ordrar_first_free_slot(lead_store($busy13), 'batteri', 'Roberto', 'jarfalla', null, lead_day('2026-10-07'));
lead_assert(($batNext['starts_at'] ?? '') === '2026-10-14 07:00:00', 'upptagen 13/10 ger 14/10, fick ' . json_encode($batNext));
$batJul = jeab_ordrar_first_free_slot(lead_store(), 'batteri', 'Roberto', 'jarfalla', null, lead_day('2026-12-22'));
lead_assert(($batJul['starts_at'] ?? '') === '2026-12-30 07:00:00', 'batteri före jul föreslås 30/12, fick ' . json_encode($batJul));
$box = jeab_ordrar_first_free_slot(lead_store(), 'laddbox', 'Elin', 'jarfalla', null, lead_day('2026-10-07'));
lead_assert($box === ['starts_at' => '2026-10-09 07:00:00', 'ends_at' => '2026-10-09 11:00:00'], 'laddbox beställd 7/10 föreslås fre 9/10 förmiddag, fick ' . json_encode($box));
$boxFri = jeab_ordrar_first_free_slot(lead_store(), 'laddbox', 'Elin', 'jarfalla', null, lead_day('2026-12-22'));
lead_assert(($boxFri['starts_at'] ?? '') === '2026-12-28 07:00:00', 'laddbox hoppar över julafton–annandag, fick ' . json_encode($boxFri));
$sol = jeab_ordrar_first_free_slot(lead_store(), 'sol', 'SamL', 'jarfalla', null, lead_day('2026-10-07'));
lead_assert(substr((string) ($sol['starts_at'] ?? ''), 0, 10) >= '2026-10-21', 'sol föreslås tidigast 14 dagar fram, fick ' . json_encode($sol));

// Boka+Beställ-förslaget (suggest_install_ymd) använder samma regel med dagens datum
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm'));
foreach (['batteri' => 'Roberto', 'laddbox' => 'Elin'] as $kind => $who) {
    $want = jeab_ordrar_first_free_slot(lead_store(), $kind, $who, 'jarfalla', null, $now);
    $got = jeab_ordrar_suggest_install_ymd(['kind' => $kind, 'company' => 'jarfalla'], lead_store());
    lead_assert($got === substr($want['starts_at'], 0, 10), "Boka+Beställ föreslår {$kind} enligt samma regel ({$got})");
}
$boxGot = jeab_ordrar_suggest_install_ymd(['kind' => 'laddbox', 'company' => 'jarfalla'], lead_store());
$boxMax = jeab_ordrar_add_workdays($now->setTime(0, 0, 0)->modify('+2 days'), 1)->format('Y-m-d');
lead_assert($boxGot <= $boxMax, "laddbox i Boka+Beställ är inte längre 14 dagar ({$boxGot})");

// Båda vägarna går genom den gemensamma regeln
$src = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$sugStart = strpos($src, 'function jeab_ordrar_suggest_install_ymd');
$sugEnd = strpos($src, 'function jeab_ordrar_suggest_delivery_ymd');
$sugBody = substr($src, (int) $sugStart, (int) $sugEnd - (int) $sugStart);
lead_assert(str_contains($sugBody, 'jeab_ordrar_first_free_slot('), 'Boka+Beställ använder jeab_ordrar_first_free_slot');
lead_assert(!str_contains($sugBody, 'max(14'), 'Boka+Beställ har inte kvar 14 dagar för alla produkter');
$bookStart = strpos($src, 'function jeab_ordrar_create_booking');
$bookEnd = strpos($src, 'function jeab_ordrar_paminelse_mmdd');
$bookBody = substr($src, (int) $bookStart, (int) $bookEnd - (int) $bookStart);
lead_assert(str_contains($bookBody, 'jeab_ordrar_first_free_slot('), 'Boka använder jeab_ordrar_first_free_slot');
lead_assert(!str_contains($src, 'tid minst två veckor framåt. Önskad'), 'hjälptexten är inte hårdkodad till två veckor');
lead_assert(str_contains(jeab_ordrar_book_lead_help('batteri'), 'fjärde arbetsdagen'), 'hjälptext batteri');
lead_assert(str_contains(jeab_ordrar_book_lead_help('laddbox'), 'två dagar'), 'hjälptext laddbox');
lead_assert(str_contains(jeab_ordrar_book_lead_help('sol'), 'två veckor'), 'hjälptext sol');

echo "OK  batteri arbetsdagar\n";
