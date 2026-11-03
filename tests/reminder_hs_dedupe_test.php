<?php

declare(strict_types=1);

/**
 * Samma Help Scout-nummer får inte ge två öppna påminnelser.
 * Nyckeln är HS-numret i anteckningen eller ett explicit id — inte telefon eller adress.
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

function hs_ids(string $text): array
{
    return jeab_reminder_hs_ids_from_text($text);
}

fm_assert(hs_ids('HS#24563') === ['24563'], 'HS#24563');
fm_assert(hs_ids('hs #24563') === ['24563'], 'HS #24563');
fm_assert(hs_ids('HS# 24563') === ['24563'], 'HS# 24563');
fm_assert(hs_ids('Help Scout #24563') === ['24563'], 'Help Scout #24563');
fm_assert(hs_ids('HelpScout #24563') === ['24563'], 'HelpScout #24563');
fm_assert(hs_ids('Se tråden i Help Scout: #24563') === ['24563'], '#24563 in Help Scout context');
fm_assert(hs_ids('#24563 (HS)') === ['24563'], '#24563 before HS');
fm_assert(
    hs_ids('https://secure.helpscout.net/conversation/24563/12345') === ['24563'],
    'Help Scout conversation URL'
);
fm_assert(hs_ids('#24563') === [], 'bare #24563 is not an HS number');
fm_assert(hs_ids('Ring 0701234567 om #offert') === [], 'phone and tag are not HS numbers');
fm_assert(hs_ids('HS#24563 och HS#24564') === ['24563', '24564'], 'two explicit HS numbers');
fm_assert(jeab_reminder_hs_id_from_value('24563') === '24563', 'numeric hs_id');
fm_assert(jeab_reminder_hs_id_from_value('HS#24563') === '24563', 'hs_id may be written as HS#');
fm_assert(jeab_reminder_hs_id_from_value('') === null, 'empty hs_id');

$path = sys_get_temp_dir() . '/jeab-hs-dedupe-' . getmypid() . '.json';
@unlink($path);
$store = new JeabJsonStore($path);

$first = jeab_reminder_create_action($store, [
    'name' => 'Test Cody',
    'comment' => "HS#999001 TEST dedupe Cody\nchat",
    'bolag' => 'JEAB',
    'phone' => '0701111111',
    'address' => 'Testgatan 1',
    'due' => '2026-09-24',
]);
$second = jeab_reminder_create_action($store, [
    'name' => 'Test Cody',
    'comment' => "HS#999001 TEST dedupe Cody\nformulär",
    'bolag' => 'JEAB',
    'phone' => '0702222222',
    'address' => 'Annan gata 9',
    'due' => '2026-09-25',
]);
$third = jeab_reminder_create_action($store, [
    'name' => 'Test Cody',
    'comment' => "HS#999001 TEST dedupe Cody\nformulär",
    'bolag' => 'JEAB',
    'due' => '2026-09-25',
]);

$open = $store->listReminders(false);
fm_assert(count($open) === 1, 'two creates with the same HS# stay one open row, got ' . count($open));
fm_assert((int) $first['id'] === (int) $second['id'] && (int) $second['id'] === (int) $third['id'], 'same row id');
$note = (string) ($store->getReminder((int) $first['id'])['note'] ?? '');
fm_assert(str_contains($note, 'chat') && str_contains($note, 'formulär'), 'different notes are appended');
fm_assert(substr_count($note, 'formulär') === 1, 'identical text is not duplicated');
fm_assert(($store->getReminder((int) $first['id'])['phone'] ?? '') === '+46701111111', 'phone on the first row is kept');
fm_assert(($store->getReminder((int) $first['id'])['address'] ?? '') === 'Testgatan 1', 'address on the first row is kept');

$other = jeab_reminder_create_action($store, [
    'name' => 'Annan kund',
    'comment' => "HS#999002 annat ärende\nchat",
    'bolag' => 'JEAB',
    'phone' => '0701111111',
    'address' => 'Testgatan 1',
    'due' => '2026-09-24',
]);
fm_assert((int) $other['id'] !== (int) $first['id'], 'a different HS# is a new row even with the same phone and address');
fm_assert(count($store->listReminders(false)) === 2, 'two open rows for two HS numbers');

$noHsA = jeab_reminder_create_action($store, [
    'name' => 'Utan nummer',
    'comment' => 'bara en anteckning',
    'bolag' => 'JEAB',
    'phone' => '0703333333',
    'address' => 'Samma väg 2',
    'due' => '2026-09-24',
]);
$noHsB = jeab_reminder_create_action($store, [
    'name' => 'Utan nummer',
    'comment' => 'en annan anteckning',
    'bolag' => 'JEAB',
    'phone' => '0703333333',
    'address' => 'Samma väg 2',
    'due' => '2026-09-24',
]);
fm_assert((int) $noHsA['id'] !== (int) $noHsB['id'], 'without an HS number, phone and address do not dedupe');

$bare = jeab_reminder_create_action($store, [
    'name' => 'Bara hash',
    'comment' => '#999001',
    'bolag' => 'JEAB',
    'due' => '2026-09-24',
]);
fm_assert((int) $bare['id'] !== (int) $first['id'], 'bare #999001 does not join the HS row');

$byField = jeab_reminder_create_action($store, [
    'name' => 'Fält-id',
    'comment' => 'från chatten',
    'hs_id' => '888002',
    'bolag' => 'JEAB',
    'due' => '2026-09-24',
]);
$byFieldAgain = jeab_create_reminder($store, 'Fält-id', '2026-09-24 00:00:00', 'formulär via hook', null, [
    'customer' => 'Fält-id',
    'company' => 'jarfalla',
    'helpscout_id' => '888002',
]);
fm_assert((int) $byField['id'] === (int) $byFieldAgain['id'], 'hs_id and helpscout_id share one open row');
$fieldNote = (string) ($store->getReminder((int) $byField['id'])['note'] ?? '');
fm_assert(str_contains($fieldNote, 'HS#888002') && str_contains($fieldNote, 'från chatten') && str_contains($fieldNote, 'formulär via hook'), 'explicit id is stored and the new note is appended');

$parsed = jeab_parse_incoming([
    'type' => 'reminder',
    'title' => 'Hook',
    'notes' => 'HS#777003 första',
    'conversation_id' => '777003',
]);
fm_assert(($parsed['conversation_id'] ?? null) === '777003', 'hook parse keeps conversation_id');
$hookA = jeab_create_reminder($store, $parsed['title'], $parsed['due_at'], $parsed['note'], null, $parsed);
$parsedB = jeab_parse_incoming([
    'type' => 'reminder',
    'title' => 'Hook',
    'notes' => 'HS#777003 andra',
    'data' => ['conversation_id' => '777003'],
]);
$hookB = jeab_create_reminder($store, $parsedB['title'], $parsedB['due_at'], $parsedB['note'], null, $parsedB);
fm_assert((int) $hookA['id'] === (int) $hookB['id'], 'hook.php path reuses the open HS row');

jeab_toggle_reminder($store, (int) $hookA['id'], true);
$hookC = jeab_create_reminder($store, 'Hook', '2026-09-24 00:00:00', 'HS#777003 efter klar', null, [
    'customer' => 'Hook',
    'company' => 'jarfalla',
]);
fm_assert((int) $hookC['id'] !== (int) $hookA['id'], 'a completed HS row does not block a new open reminder');
fm_assert(empty($store->getReminder((int) $hookC['id'])['completed_at']), 'the new HS row is open');

$booking = [
    'id' => 5,
    'customer_name' => 'Bokningstest',
    'phone' => '0701234567',
    'email' => null,
    'address' => 'Bokningsgatan 5',
    'notes' => 'HS#666004 bokning',
    'job_type' => 'sol',
    'starts_at' => '2026-09-24 10:00:00',
    'company' => 'jarfalla',
    'assignee' => '',
];
$fromBooking = jeab_reminder_from_booking($store, $booking);
$fromBookingAgain = jeab_reminder_from_booking($store, $booking);
fm_assert((int) $fromBooking['id'] === (int) $fromBookingAgain['id'], 'booking.remind still reuses findOpenReminderByBooking');
fm_assert((int) ($fromBookingAgain['booking_id'] ?? 0) === 5, 'reused booking reminder keeps booking_id');
$hsBesideBooking = jeab_create_reminder($store, 'Bredvid', '2026-09-24 00:00:00', 'HS#666004 från bot', null, [
    'customer' => 'Bredvid',
    'company' => 'jarfalla',
]);
fm_assert((int) $hsBesideBooking['id'] === (int) $fromBooking['id'], 'a later bot create with the same HS# appends onto the open booking row');
$bookingAgain = jeab_reminder_from_booking($store, $booking);
fm_assert((int) $bookingAgain['id'] === (int) $fromBooking['id'], 'booking.remind still returns the same open row');

$otherBooking = $booking;
$otherBooking['id'] = 6;
$otherBooking['notes'] = 'HS#666004 annan bokning';
$secondBooking = jeab_reminder_from_booking($store, $otherBooking);
fm_assert((int) $secondBooking['id'] !== (int) $fromBooking['id'], 'a new booking_id still inserts even when the note has an existing HS#');
fm_assert((int) ($store->findOpenReminderByBooking(6)['id'] ?? 0) === (int) $secondBooking['id'], 'findOpenReminderByBooking is unchanged');

@unlink($path);
echo "reminder hs dedupe: all passed\n";
