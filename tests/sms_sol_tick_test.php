<?php

declare(strict_types=1);

/**
 * Ren sol ska in i samma SMS-tick som övriga jobb.
 * Tomt telefonnummer så tick inte anropar SureSMS.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

function fm_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

date_default_timezone_set('Europe/Stockholm');

final class SmsTickFakeStore implements JeabStore
{
    /** @param list<array<string, mixed>> $due */
    public function __construct(private array $due)
    {
    }

    public function listReminders(bool $includeCompleted = true): array
    {
        return [];
    }

    public function getReminder(int $id): ?array
    {
        return null;
    }

    public function insertReminder(array $data): array
    {
        return [];
    }

    public function saveReminder(int $id, array $fields): array
    {
        return [];
    }

    public function incrementReminderTap(int $id, string $field): array
    {
        return [];
    }

    public function deleteReminders(array $ids): int
    {
        return 0;
    }

    public function findOpenReminderByBooking(int $bookingId): ?array
    {
        return null;
    }

    public function findCustomerByName(string $name): ?array
    {
        return null;
    }

    public function insertCustomer(string $name, string $createdAt): array
    {
        return [];
    }

    public function listCustomers(): array
    {
        return [];
    }

    public function recentAssignees(int $limit): array
    {
        return [];
    }

    public function getBooking(int $id): ?array
    {
        return null;
    }

    public function bookingsBetween(string $from, string $to): array
    {
        return $this->due;
    }

    public function bookingsCreatedBetween(string $from, string $to): array
    {
        return [];
    }

    public function bookingsUpdatedBetween(string $from, string $to): array
    {
        return [];
    }

    public function listAllBookings(): array
    {
        return [];
    }

    public function insertBooking(array $data): array
    {
        return [];
    }

    public function saveBooking(int $id, array $fields): array
    {
        return [];
    }

    public function setting(string $key, ?string $default = null): ?string
    {
        return $default;
    }

    public function setSetting(string $key, string $value): void
    {
    }
}

fm_assert(!function_exists('jeab_booking_jobs_skip_sms'), 'skip-sol-funktionen är borta');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/sms.php');
fm_assert(!str_contains($src, 'jeab_booking_jobs_skip_sms'), 'sms.php anropar inte skip-sol');

$allDay = [
    'starts_at' => '2026-09-25 00:00:00',
    'ends_at' => '2026-09-26 00:00:00',
    'job_type' => 'sol',
];
$timed = [
    'starts_at' => '2026-09-25 09:00:00',
    'ends_at' => '2026-09-25 12:00:00',
    'job_type' => 'sol',
];
fm_assert(jeab_sms_text($allDay) === 'Demo Energi Järfälla kommer imorgon.', 'heldag utan klocka');
fm_assert(jeab_sms_text($timed) === 'Demo Energi Järfälla kommer imorgon kl 9–12.', 'klockslag behålls');

$tz = new DateTimeZone('Europe/Stockholm');
$future = (new DateTimeImmutable('now', $tz))->modify('+8 hours');
$past = (new DateTimeImmutable('now', $tz))->modify('-2 hours');
$stamp = static function (DateTimeImmutable $at): string {
    return $at->format('Y-m-d H:i:s');
};
$created = $stamp($future->modify('-2 days'));

$due = [
    [
        'id' => 880011,
        'starts_at' => $stamp($future),
        'ends_at' => $stamp($future->modify('+4 hours')),
        'job_type' => 'sol',
        'phone' => '',
        'email' => '',
        'customer_name' => 'Sol Test',
        'created_at' => $created,
        'updated_at' => $created,
    ],
    [
        'id' => 880012,
        'starts_at' => $stamp($future->modify('+1 hour')),
        'ends_at' => $stamp($future->modify('+3 hours')),
        'job_type' => 'laddbox',
        'phone' => '',
        'email' => '',
        'customer_name' => 'Ladd Test',
        'created_at' => $created,
        'updated_at' => $created,
    ],
    [
        'id' => 880013,
        'starts_at' => $stamp($past),
        'ends_at' => $stamp($past->modify('+1 hour')),
        'job_type' => 'sol',
        'phone' => '0700000000',
        'email' => '',
        'customer_name' => 'Passerad',
        'created_at' => $created,
        'updated_at' => $created,
    ],
];

$sentPath = jeab_sms_sent_path();
$before = is_file($sentPath) ? (string) file_get_contents($sentPath) : null;
$result = jeab_sms_tick(new SmsTickFakeStore($due));
$after = is_file($sentPath) ? (string) file_get_contents($sentPath) : null;

fm_assert(($result['sent'] ?? null) === 0, 'inget SMS skickades');
fm_assert(($result['skipped'] ?? null) === 1, 'bara passerad tid hoppas över, inte ren sol');
fm_assert(($result['errors'] ?? null) === [], 'SureSMS anropades inte');
fm_assert($before === $after, 'sms-sent.json orörd');

fm_assert(jeab_booking_sms_allowed(['job_type' => 'batteri', 'company' => 'jarfalla']), 'batteri får SMS');
fm_assert(jeab_booking_sms_allowed(['job_type' => 'laddbox', 'company' => 'malmo']), 'laddbox får SMS');
fm_assert(jeab_booking_sms_allowed(['job_type' => 'sol', 'company' => 'jarfalla']), 'Järfälla-sol får SMS');
fm_assert(!jeab_booking_sms_allowed(['job_type' => 'sol', 'company' => 'malmo']), 'Malmö-sol får inget SMS');
fm_assert(!jeab_booking_sms_allowed(['job_type' => 'service', 'company' => 'jarfalla']), 'service får inget SMS');
fm_assert(!jeab_booking_sms_allowed(['job_type' => '', 'company' => 'jarfalla']), 'bokning utan produkt får inget SMS');
fm_assert(!jeab_booking_sms_allowed(['company' => 'jarfalla']), 'Outlook-möte utan tagg får inget SMS');

$phones = [];
$GLOBALS['JEAB_SMS_SEND'] = static function (string $to) use (&$phones): array {
    $phones[] = $to;
    return ['ok' => true];
};
$productDue = [
    [
        'id' => 880021,
        'starts_at' => $stamp($future),
        'ends_at' => $stamp($future->modify('+2 hours')),
        'job_type' => 'batteri',
        'phone' => '+46701111111',
        'email' => '',
        'customer_name' => 'Batteri',
        'company' => 'jarfalla',
        'created_at' => $created,
        'updated_at' => $created,
    ],
    [
        'id' => 880022,
        'starts_at' => $stamp($future),
        'ends_at' => $stamp($future->modify('+2 hours')),
        'job_type' => 'service',
        'phone' => '+46702222222',
        'email' => '',
        'customer_name' => 'Felsök',
        'company' => 'jarfalla',
        'created_at' => $created,
        'updated_at' => $created,
    ],
    [
        'id' => 880023,
        'starts_at' => $stamp($future),
        'ends_at' => $stamp($future->modify('+1 hour')),
        'job_type' => '',
        'phone' => '+46703333333',
        'email' => '',
        'customer_name' => 'Birgitta',
        'company' => 'jarfalla',
        'created_at' => $created,
        'updated_at' => $created,
    ],
    [
        'id' => 880024,
        'starts_at' => $stamp($future),
        'ends_at' => $stamp($future->modify('+2 hours')),
        'job_type' => 'laddbox',
        'phone' => '+46704444444',
        'email' => '',
        'customer_name' => 'Laddbox',
        'company' => 'jarfalla',
        'created_at' => $created,
        'updated_at' => $created,
    ],
];
$productTick = jeab_sms_tick(new SmsTickFakeStore($productDue));
fm_assert(($productTick['sent'] ?? null) === 2, 'bara batteri och laddbox skickas, fick ' . (int) ($productTick['sent'] ?? -1));
fm_assert($phones === ['+46701111111', '+46704444444'], 'service och omärkt möte får inget nummer, fick ' . json_encode($phones));
unset($GLOBALS['JEAB_SMS_SEND']);

echo "sms sol tick: ok\n";
