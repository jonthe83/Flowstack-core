<?php

declare(strict_types=1);

/**
 * Solceller bokas som fem arbetsdagar, inte som en halvdag.
 */

$tmp = sys_get_temp_dir() . '/jeab-sol-days-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';

function sol_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$tz = new DateTimeZone('Europe/Stockholm');
$monday = new DateTimeImmutable('2026-10-05 00:00:00', $tz);
$span = jeab_ordrar_sol_span_from_day($monday);
sol_assert($span['starts_at'] === '2026-10-05 00:00:00', 'sol startar vid midnatt den 5:e, fick ' . $span['starts_at']);
sol_assert($span['ends_at'] === '2026-10-10 00:00:00', 'fem arbetsdagar slutar lördag 00:00, fick ' . $span['ends_at']);
sol_assert(jeab_booking_is_all_day($span['starts_at'], $span['ends_at']), 'sol är en heldagsbokning');
sol_assert(jeab_booking_weekday_count($span['starts_at'], $span['ends_at']) === 5, 'sol täcker fem vardagar');

$saturday = new DateTimeImmutable('2026-10-03 00:00:00', $tz);
$fromSat = jeab_ordrar_sol_span_from_day($saturday);
sol_assert($fromSat['starts_at'] === '2026-10-05 00:00:00', 'lördag flyttas till måndag');

$free = jeab_ordrar_pick_sol_span([], [], 'SamL', $monday);
sol_assert($free['starts_at'] === '2026-10-05 00:00:00' && $free['ends_at'] === '2026-10-10 00:00:00', 'ledig vecka börjar den 5:e');

$busyMonday = [[
    'company' => 'jarfalla',
    'assignees' => ['SamL'],
    'assignee' => 'SamL',
    'starts_at' => '2026-10-05 07:00:00',
    'ends_at' => '2026-10-05 11:00:00',
    'status' => 'booked',
]];
$next = jeab_ordrar_pick_sol_span($busyMonday, [], 'SamL', $monday);
sol_assert($next['starts_at'] === '2026-10-06 00:00:00', 'upptagen måndag ger nästa vardag, fick ' . $next['starts_at']);
sol_assert(jeab_booking_weekday_count($next['starts_at'], $next['ends_at']) === 5, 'nästa block är också fem vardagar');
sol_assert(jeab_booking_is_all_day($next['starts_at'], $next['ends_at']), 'nästa block är heldag');

jeab_ordrar_external_live(false);
$half = jeab_ordrar_first_free_halfday_on_or_after(new class implements JeabStore {
    public function listReminders(bool $includeCompleted = true): array { return []; }
    public function getReminder(int $id): ?array { return null; }
    public function insertReminder(array $data): array { return []; }
    public function saveReminder(int $id, array $fields): array { return []; }
    public function incrementReminderTap(int $id, string $field): array { return []; }
    public function deleteReminders(array $ids): int { return 0; }
    public function findOpenReminderByBooking(int $bookingId): ?array { return null; }
    public function findCustomerByName(string $name): ?array { return null; }
    public function insertCustomer(string $name, string $createdAt): array { return []; }
    public function listCustomers(): array { return []; }
    public function recentAssignees(int $limit): array { return []; }
    public function getBooking(int $id): ?array { return null; }
    public function bookingsBetween(string $from, string $to): array { return []; }
    public function bookingsCreatedBetween(string $from, string $to): array { return []; }
    public function bookingsUpdatedBetween(string $from, string $to): array { return []; }
    public function listAllBookings(): array { return []; }
    public function insertBooking(array $data): array { return []; }
    public function saveBooking(int $id, array $fields): array { return []; }
    public function setting(string $key, ?string $default = null): ?string { return $default; }
    public function setSetting(string $key, string $value): void {}
}, 'Elin', '2026-10-05');
sol_assert(str_contains($half['starts_at'], '07:00:00') || str_contains($half['starts_at'], '12:00:00'), 'elbilsladdare är fortfarande en halvdag');
sol_assert(!jeab_booking_is_all_day($half['starts_at'], $half['ends_at']), 'halvdag är inte heldag');

echo "OK  sol fem dagar\n";
