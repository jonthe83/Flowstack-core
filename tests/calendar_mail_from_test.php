<?php

declare(strict_types=1);

/**
 * Kalender-tick: Malmö-tilldelad bokning → From kontakt@malmo.example.test.
 * Skickar inget riktigt mejl.
 */

$tmp = sys_get_temp_dir() . '/jeab-cal-mail-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
file_put_contents($tmp . '/helpscout.json', json_encode(['mailbox_id' => 372708], JSON_UNESCAPED_UNICODE));

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

fm_assert(jeab_company_catalog()['malmo']['mail_name'] === 'Demo Energi Malmö', 'katalogens MEAB-namn');
fm_assert(jeab_company_catalog()['malmo']['domain'] === 'malmo.example.test', 'katalogens MEAB-domän');
fm_assert(jeab_system_mail_address('malmo') === 'kontakt@malmo.example.test', 'Malmö From-adress');
fm_assert(jeab_system_mail_from_name('malmo') === 'Demo Energi Malmö', 'Malmö visningsnamn');
fm_assert(jeab_system_mail_address('meab') === 'kontakt@malmo.example.test', 'meab är samma From');
fm_assert(jeab_system_mail_address() === 'kontakt@jarfalla.example.test', 'tomt bolag är JEAB');
fm_assert(jeab_system_mail_from_name('jarfalla') === 'Demo Energi Järfälla', 'JEAB visningsnamn');

fm_assert(
    jeab_calendar_mail_company(['assignee' => 'Sam W']) === 'malmo',
    'Sam W utan company är Malmö'
);
fm_assert(
    jeab_calendar_mail_company(['assignees' => ['Kim']]) === 'malmo',
    'Kim är Malmö'
);
fm_assert(
    jeab_calendar_mail_company(['assignees' => '["Abol"]']) === 'malmo',
    'Abol i JSON är Malmö'
);
fm_assert(
    jeab_calendar_mail_company(['company' => 'meab', 'assignee' => 'Roberto']) === 'malmo',
    'sparad company vinner'
);
fm_assert(
    jeab_calendar_mail_company(['company' => 'jarfalla', 'assignee' => 'Sam W']) === 'jarfalla',
    'sparad JEAB-company vinner'
);
fm_assert(
    jeab_calendar_mail_company(['assignee' => 'Elin']) === 'jarfalla',
    'Elin är Järfälla'
);
fm_assert(
    jeab_calendar_mail_company(['assignee' => 'Sam']) === 'jarfalla',
    'tvetydig Sam gissas inte till Malmö'
);
fm_assert(
    jeab_calendar_mail_company(['company' => 'Malmö']) === 'malmo',
    'Malmö normaliseras'
);

$jonatan = ['assignee' => 'Sam W', 'starts_at' => '2026-09-25 09:00:00'];
fm_assert(
    jeab_system_mail_address(jeab_calendar_mail_company($jonatan)) === 'kontakt@malmo.example.test',
    'Sam W-bokning får kontakt@malmo.example.test'
);
fm_assert(
    jeab_system_mail_from_name(jeab_calendar_mail_company($jonatan)) === 'Demo Energi Malmö',
    'Sam W-bokning får visningsnamnet Demo Energi Malmö'
);
$roberto = ['company' => 'jarfalla', 'assignee' => 'Roberto'];
fm_assert(
    jeab_system_mail_address(jeab_calendar_mail_company($roberto)) === 'kontakt@jarfalla.example.test',
    'Roberto-bokning behåller JEAB From'
);

fm_assert(jeab_helpscout_mailbox_id('jarfalla') === 372708, 'JEAB-brevlåda oförändrad');
fm_assert(jeab_helpscout_mailbox_id('malmo') === null, 'utan MEAB-nyckel används inte JEAB-brevlådan');
file_put_contents($tmp . '/helpscout.json', json_encode([
    'mailbox_id' => 372708,
    'mailbox_id_malmo' => 880021,
], JSON_UNESCAPED_UNICODE));
fm_assert(jeab_helpscout_mailbox_id('malmo') === 880021, 'explicit MEAB-brevlåda');
fm_assert(jeab_helpscout_mailbox_id(null) === 372708, 'JEAB-brevlåda finns kvar');

fm_assert(jeab_sms_text([
    'starts_at' => '2026-09-25 09:00:00',
    'ends_at' => '2026-09-25 12:00:00',
]) === 'Demo Energi Järfälla kommer imorgon kl 9–12.', 'SMS-texten är orörd');

final class CalendarMailFakeStore implements JeabStore
{
    /** @param list<array<string, mixed>> $created
     *  @param list<array<string, mixed>> $updated
     */
    /** @var array{0: string, 1: string} */
    public array $createdWindow = ['', ''];

    /** @var array{0: string, 1: string} */
    public array $updatedWindow = ['', ''];

    public function __construct(private array $created, private array $updated)
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
        return [];
    }

    public function bookingsCreatedBetween(string $from, string $to): array
    {
        $this->createdWindow = [$from, $to];
        return $this->created;
    }

    public function bookingsUpdatedBetween(string $from, string $to): array
    {
        $this->updatedWindow = [$from, $to];
        $rows = [];
        foreach (array_merge($this->created, $this->updated) as $row) {
            $updated = (string) ($row['updated_at'] ?? '');
            if ($updated !== '' && $updated >= $from && $updated < $to) {
                $rows[] = $row;
            }
        }
        return $rows;
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

date_default_timezone_set('Europe/Stockholm');
$tz = new DateTimeZone('Europe/Stockholm');
$now = new DateTimeImmutable('now', $tz);
$stamp = static fn (DateTimeImmutable $at): string => $at->format('Y-m-d H:i:s');
$createdAt = $stamp($now->modify('-3 hours'));
$updatedAt = $stamp($now->modify('-2 hours -5 minutes'));
$tooYoung = $stamp($now->modify('-30 minutes'));
$start = $stamp($now->modify('+10 hours'));
$end = $stamp($now->modify('+12 hours'));

$captured = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$captured): array {
    $captured[] = $opts;
    return ['ok' => true, 'path' => 'graph', 'error' => null, 'id' => 'stub'];
};

$mailStore = new CalendarMailFakeStore(
    [[
        'id' => 991001,
        'starts_at' => $start,
        'ends_at' => $end,
        'job_type' => 'laddbox',
        'phone' => '',
        'email' => 'malmo-kund@kund.se',
        'customer_name' => 'Test Malmo',
        'assignee' => 'Sam W',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ], [
        'id' => 991003,
        'starts_at' => $start,
        'ends_at' => $end,
        'job_type' => 'laddbox',
        'phone' => '',
        'email' => 'for-ung@kund.se',
        'customer_name' => 'For ung',
        'assignee' => 'Sam W',
        'created_at' => $tooYoung,
        'updated_at' => $tooYoung,
    ]],
    [[
        'id' => 991002,
        'starts_at' => $start,
        'ends_at' => $end,
        'job_type' => 'batteri',
        'phone' => '',
        'email' => 'jeab-kund@kund.se',
        'customer_name' => 'Test Jeab',
        'company' => 'jarfalla',
        'assignee' => 'Roberto',
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
    ], [
        'id' => 991004,
        'starts_at' => $start,
        'ends_at' => $end,
        'job_type' => 'batteri',
        'phone' => '',
        'email' => 'andrad-nyss@kund.se',
        'customer_name' => 'Andrad nyss',
        'company' => 'jarfalla',
        'assignee' => 'Roberto',
        'created_at' => $stamp($now->modify('-2 days')),
        'updated_at' => $tooYoung,
    ]]
);
$result = jeab_sms_tick($mailStore);

fm_assert(($result['sent'] ?? null) === 0, 'tick skickade inget SMS');
fm_assert(($result['sent_email'] ?? null) === 2, 'bara bokningar som är minst två timmar gamla mejlas');
fm_assert(count($captured) === 2, 'två systemmejl');
fm_assert(!in_array('for-ung@kund.se', array_column($captured, 'to'), true), 'skapning yngre än två timmar mejlas inte');
fm_assert(!in_array('andrad-nyss@kund.se', array_column($captured, 'to'), true), 'ändring yngre än två timmar mejlas inte');
fm_assert($mailStore->createdWindow === ['', ''], 'tick läser inte ett separat skapandefönster');
fm_assert($mailStore->updatedWindow[0] === jeab_booking_mail_due_from(), 'nedre gräns är öppen, inte senaste 4 timmarna');
$untilAge = (new DateTimeImmutable('now', $tz))->getTimestamp()
    - (new DateTimeImmutable($mailStore->updatedWindow[1], $tz))->getTimestamp();
fm_assert(abs($untilAge - (2 * 60 * 60 - 1)) <= 2, 'övre gräns är två timmar, fick ' . $untilAge . ' sekunder');
fm_assert(($captured[0]['company'] ?? '') === 'malmo', 'skapamejl för Sam W är malmo');
fm_assert(($captured[0]['to'] ?? '') === 'malmo-kund@kund.se', 'Malmö-mottagare');
fm_assert(
    jeab_system_mail_address((string) $captured[0]['company']) === 'kontakt@malmo.example.test',
    'tickens Malmö-bolag mappar till kontakt@malmo.example.test'
);
fm_assert(($captured[1]['company'] ?? '') === 'jarfalla', 'ändringsmejl för Roberto är jarfalla');
fm_assert(
    jeab_system_mail_address((string) $captured[1]['company']) === 'kontakt@jarfalla.example.test',
    'tickens JEAB-bolag mappar till kontakt@jarfalla.example.test'
);

echo "calendar mail from: ok\n";
